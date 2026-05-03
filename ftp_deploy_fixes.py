import ftplib
import os

try:
    ftp = ftplib.FTP_TLS('ftp.goldwing.org.au')
    ftp.login('goldwing', 'aga@wagga@')
    ftp.prot_p()
    ftp.set_pasv(True)

    def try_chdir(path):
        try:
            ftp.cwd(path)
            return True
        except Exception:
            return False

    if not try_chdir('public_html/draft.goldwing.org.au') and not try_chdir('draft.goldwing.org.au') and not try_chdir('public_html/draft') and not try_chdir('draft'):
        print("Could not locate the draft environment folder via FTP!")
        ftp.quit()
        exit()

    print(f"Working directory: {ftp.pwd()}")

    def ensure_dir(remote_dir):
        """Create remote directory if it doesn't exist."""
        parts = remote_dir.split('/')
        for i in range(len(parts)):
            partial = '/'.join(parts[:i+1])
            if partial:
                try:
                    ftp.mkd(partial)
                except:
                    pass

    def upload_file(local_path, remote_path):
        try:
            # Ensure parent directory exists
            parent = '/'.join(remote_path.split('/')[:-1])
            if parent:
                ensure_dir(parent)
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            print(f"> Uploaded {local_path} -> {remote_path}")
        except Exception as e:
            print(f"! Failed to upload {local_path}: {e}")

    # Upload code changes
    upload_file('public_html/index.php', 'index.php')
    upload_file('app/Views/partials/sponsors.php', 'app/Views/partials/sponsors.php')
    upload_file('public_html/tmp_update_page.php', 'tmp_update_page.php')

    # Upload constitution PDF
    upload_file('public_html/uploads/about/constitution.pdf', 'uploads/about/constitution.pdf')

    print("\nAll files uploaded successfully!")
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
