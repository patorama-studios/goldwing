#!/usr/bin/env python3
"""
One-shot FTP uploader for a specific list of changed files.

Reads paths from stdin (one per line, relative to the project root) and uploads
each to the live server using credentials from scripts/ftp.env.

Usage:
    . scripts/ftp.env && git show --stat --name-only HEAD | \
        awk '/^[a-zA-Z0-9._\\/-]+$/' | python3 scripts/ftp_upload_changed.py
"""
from __future__ import annotations

import ftplib
import os
import posixpath
import sys
from pathlib import Path


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def connect_ftp() -> ftplib.FTP:
    host = env("FTP_HOST")
    user = env("FTP_USER")
    password = env("FTP_PASS")
    port = int(env("FTP_PORT", "21") or "21")
    use_tls = env("FTP_TLS", "1") not in ("0", "false", "False")

    if not host or not user or not password:
        raise RuntimeError("Missing FTP_HOST/FTP_USER/FTP_PASS — did you `source scripts/ftp.env`?")

    ftp: ftplib.FTP = ftplib.FTP_TLS() if use_tls else ftplib.FTP()
    ftp.connect(host=host, port=port, timeout=20)
    ftp.login(user=user, passwd=password)
    if use_tls and isinstance(ftp, ftplib.FTP_TLS):
        ftp.prot_p()
    return ftp


def ensure_remote_dir(ftp: ftplib.FTP, remote_dir: str) -> None:
    try:
        ftp.cwd("/")
    except ftplib.error_perm:
        ftp.cwd(".")
    if remote_dir in ("", "/"):
        return
    for part in [p for p in remote_dir.split("/") if p]:
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)


def upload_file(ftp: ftplib.FTP, local_path: Path, remote_path: str) -> None:
    remote_dir = posixpath.dirname(remote_path)
    ensure_remote_dir(ftp, remote_dir)
    with local_path.open("rb") as fh:
        ftp.storbinary(f"STOR {posixpath.basename(remote_path)}", fh)


def main() -> int:
    base_dir = Path(env("LOCAL_ROOT", str(Path(__file__).resolve().parents[1]))).resolve()
    remote_root = env("FTP_ROOT", "").strip().strip("/")

    paths: list[Path] = []
    for line in sys.stdin:
        rel = line.strip()
        if not rel:
            continue
        candidate = (base_dir / rel).resolve()
        if not candidate.exists() or not candidate.is_file():
            print(f"SKIP (missing): {rel}", file=sys.stderr)
            continue
        try:
            candidate.relative_to(base_dir)
        except ValueError:
            print(f"SKIP (outside project): {rel}", file=sys.stderr)
            continue
        paths.append(candidate)

    if not paths:
        print("Nothing to upload.", file=sys.stderr)
        return 1

    print(f"Connecting to {env('FTP_HOST')} as {env('FTP_USER')} ...", file=sys.stderr)
    ftp = connect_ftp()
    try:
        for path in paths:
            rel = path.relative_to(base_dir).as_posix()
            remote_path = posixpath.join(remote_root, rel) if remote_root else rel
            print(f"UP  {rel}", file=sys.stderr)
            upload_file(ftp, path, remote_path)
        print(f"Done — {len(paths)} file(s) uploaded.", file=sys.stderr)
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
