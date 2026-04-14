import ftplib

try:
    # Most cPanel FTP servers support explicit TLS
    ftp = ftplib.FTP_TLS('ftp.goldwing.org.au')
    ftp.login('goldwing', 'aga@wagga@')
    ftp.prot_p() # Secure data connection
    ftp.set_pasv(True)
    
    print("Logged in successfully with TLS.")
    
    def try_chdir(path):
        try:
            ftp.cwd(path)
            return True
        except Exception as e:
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
            print(f"> Uploaded {local_path} as {remote_path}")
        except Exception as e:
            print(f"! Failed to upload {local_path}: {e}")

    # Now upload the fixes
    upload_file('app/Services/LoginRateLimiter.php', 'app/Services/LoginRateLimiter.php')
    upload_file('app/Services/EmailOtpService.php', 'app/Services/EmailOtpService.php')
    print("Done uploading")
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
