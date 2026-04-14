import ftplib
import subprocess

try:
    ftp = ftplib.FTP('ftp.goldwing.org.au')
    ftp.login('goldwing', 'aga@wagga@')
    print("Logged in successfully.")
    
    # Enable passive mode
    ftp.set_pasv(True)
    
    # List root
    print("Root listing:")
    ftp.dir()
    
    # Look for draft.goldwing.org.au
    draft_dir = 'public_html/draft.goldwing.org.au' # Default cPanel addon domain behavior
    
    def try_chdir(path):
        try:
            ftp.cwd(path)
            return True
        except Exception as e:
            print(f"Cannot chdir to {path}: {e}")
            return False

    targets = [
        'public_html/draft.goldwing.org.au',
        'public_html/draft',
        'draft.goldwing.org.au',
        'draft'
    ]
    
    found_dir = None
    for target in targets:
        if try_chdir(target):
            found_dir = target
            break
            
    if not found_dir:
        print("Could not locate the draft environment folder via FTP!")
        ftp.quit()
        exit()
        
    print(f"\nTargeting draft dir: {found_dir}")
    
    # Let's verify we found the dir and upload
    def upload_file(local_path, remote_path):
        try:
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            print(f"> Uploaded {local_path} to current directory as {remote_path}")
        except Exception as e:
            print(f"! Failed to upload {local_path}: {e}")

    # Now upload the fixes
    upload_file('../../app/Services/LoginRateLimiter.php', 'app/Services/LoginRateLimiter.php')
    upload_file('../../app/Services/EmailOtpService.php', 'app/Services/EmailOtpService.php')
    upload_file('../../database/migrations/2026_04_14_add_downloads_column.sql', 'database/migrations/2026_04_14_add_downloads_column.sql')
    print("Done uploading")
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
