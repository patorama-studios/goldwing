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

    if not try_chdir('draft.goldwing.org.au') and not try_chdir('public_html/draft.goldwing.org.au'):
        print("Could not locate the draft root folder via FTP!")
        ftp.quit()
        exit()
        
    def upload_file(local_path, remote_path):
        try:
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            print(f"SUCCESS: Uploaded {local_path} to {remote_path}")
        except Exception as e:
            print(f"FAILED: Cannot upload {local_path} to {remote_path} -> {e}")

    # The paths
    upload_file('app/Views/partials/backend_member_sidebar.php', 'app/Views/partials/backend_member_sidebar.php')
    
    # We must ensure we upload to the right directory
    ftp.cwd('member')
    upload_file('public_html/member/index.php', 'index.php')
    
    ftp.cwd('..')
    upload_file('public_html/migrate_dealers.php', 'migrate_dealers.php')
    
    print("Deployment complete.")
    ftp.quit()
except Exception as e:
    print(f"Connection Error: {e}")
