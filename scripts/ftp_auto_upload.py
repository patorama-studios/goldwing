#!/usr/bin/env python3
"""
Simple FTP/FTPS auto-uploader (polling, no external deps).
Set credentials via environment variables; run with --full for initial sync.
"""
from __future__ import annotations

import argparse
import ftplib
import os
import posixpath
import time
from pathlib import Path
from typing import Dict, Iterable, Tuple


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def parse_csv(value: str) -> Tuple[str, ...]:
    if not value:
        return ()
    return tuple([item.strip() for item in value.split(",") if item.strip()])


def should_skip(
    path: Path,
    exclude_dirs: Tuple[str, ...],
    exclude_files: Tuple[str, ...],
    exclude_dir_prefixes: Tuple[str, ...],
    exclude_file_prefixes: Tuple[str, ...],
    skip_dotfiles: bool,
) -> bool:
    parts = path.parts
    if skip_dotfiles and any(part.startswith(".") for part in parts):
        return True
    if exclude_dirs and any(part in exclude_dirs for part in parts):
        return True
    if exclude_dir_prefixes and any(part.startswith(prefix) for part in parts for prefix in exclude_dir_prefixes):
        return True
    if exclude_files and path.name in exclude_files:
        return True
    if exclude_file_prefixes and any(path.name.startswith(prefix) for prefix in exclude_file_prefixes):
        return True
    return False


def iter_files(
    base: Path,
    include_dirs: Tuple[str, ...],
    exclude_dirs: Tuple[str, ...],
    exclude_files: Tuple[str, ...],
    exclude_dir_prefixes: Tuple[str, ...],
    exclude_file_prefixes: Tuple[str, ...],
    skip_dotfiles: bool,
) -> Iterable[Path]:
    targets = [base / d for d in include_dirs] if include_dirs else [base]
    for target in targets:
        if not target.exists():
            continue
        for root, dirs, files in os.walk(target):
            root_path = Path(root)
            if should_skip(root_path, exclude_dirs, exclude_files, exclude_dir_prefixes, exclude_file_prefixes, skip_dotfiles):
                dirs[:] = []
                continue
            dirs[:] = [
                d
                for d in dirs
                if not should_skip(root_path / d, exclude_dirs, exclude_files, exclude_dir_prefixes, exclude_file_prefixes, skip_dotfiles)
            ]
            for name in files:
                file_path = root_path / name
                if should_skip(file_path, exclude_dirs, exclude_files, exclude_dir_prefixes, exclude_file_prefixes, skip_dotfiles):
                    continue
                yield file_path


def connect_ftp() -> ftplib.FTP:
    host = env("FTP_HOST")
    user = env("FTP_USER")
    password = env("FTP_PASS")
    port = int(env("FTP_PORT", "21") or "21")
    use_tls = env("FTP_TLS", "1") not in ("0", "false", "False")

    if not host or not user or not password:
        raise RuntimeError("Missing FTP_HOST/FTP_USER/FTP_PASS environment variables.")

    if use_tls:
        ftp = ftplib.FTP_TLS()
    else:
        ftp = ftplib.FTP()

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
    parts = [p for p in remote_dir.split("/") if p]
    for part in parts:
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


def build_snapshot(files: Iterable[Path]) -> Dict[Path, Tuple[float, int]]:
    snapshot: Dict[Path, Tuple[float, int]] = {}
    for path in files:
        stat = path.stat()
        snapshot[path] = (stat.st_mtime, stat.st_size)
    return snapshot


def main() -> int:
    parser = argparse.ArgumentParser(description="FTP auto-upload (polling).")
    parser.add_argument("--full", action="store_true", help="Upload all files on startup.")
    parser.add_argument("--once", action="store_true", help="Run a single sync pass and exit.")
    args = parser.parse_args()

    base_dir = Path(env("LOCAL_ROOT", str(Path(__file__).resolve().parents[1]))).resolve()
    include_dirs = parse_csv(env("INCLUDE_DIRS", "public_html,app,config,database,cron,includes,calendar"))
    exclude_dirs = parse_csv(env("EXCLUDE_DIRS", ".git,New design layout,node_modules,vendor"))
    exclude_files = parse_csv(env("EXCLUDE_FILES", ".DS_Store"))
    exclude_dir_prefixes = parse_csv(env("EXCLUDE_DIR_PREFIXES", "_duplicates"))
    exclude_file_prefixes = parse_csv(env("EXCLUDE_FILE_PREFIXES", "_duplicates"))
    skip_dotfiles = env("SKIP_DOTFILES", "1") not in ("0", "false", "False")
    poll_seconds = float(env("POLL_SECONDS", "2") or "2")
    remote_root = env("FTP_ROOT", "").strip().strip("/")

    last_snapshot: Dict[Path, Tuple[float, int]] = {}

    def remote_path_for(local_path: Path) -> str:
        rel = local_path.relative_to(base_dir).as_posix()
        return posixpath.join(remote_root, rel) if remote_root else rel

    def sync_once(force_all: bool = False) -> None:
        nonlocal last_snapshot
        files = list(
            iter_files(
                base_dir,
                include_dirs,
                exclude_dirs,
                exclude_files,
                exclude_dir_prefixes,
                exclude_file_prefixes,
                skip_dotfiles,
            )
        )
        current = build_snapshot(files)

        to_upload = []
        if force_all or not last_snapshot:
            to_upload = list(current.keys())
        else:
            for path, meta in current.items():
                if path not in last_snapshot or last_snapshot[path] != meta:
                    to_upload.append(path)

        if not to_upload:
            last_snapshot = current
            return

        ftp = connect_ftp()
        try:
            for path in to_upload:
                remote_path = remote_path_for(path)
                print(f"Uploading {path} -> {remote_path}")
                upload_file(ftp, path, remote_path)
        finally:
            try:
                ftp.quit()
            except Exception:
                ftp.close()

        last_snapshot = current

    print("Starting FTP auto-uploader.")
    print(f"Local root: {base_dir}")
    print(f"Remote root: {remote_root or '(account root)'}")
    print(f"Watching: {', '.join(include_dirs) if include_dirs else 'all'}")

    sync_once(force_all=args.full)
    if args.once:
        return 0

    while True:
        time.sleep(poll_seconds)
        try:
            sync_once()
        except Exception as exc:
            print(f"Sync error: {exc}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
