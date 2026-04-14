import ftplib
import os

try:
    ftp = ftplib.FTP('ftp.goldwing.org.au')
    ftp.login('goldwing', 'aga@wagga@')
    print("Logged in successfully.")
    
    # List root
    print("Root listing:")
    ftp.dir()
    
    # Look for draft.goldwing.org.au
    draft_dir = 'public_html/draft' # Default guess
    lines = []
    ftp.retrlines('LIST', lines.append)
    for line in lines:
        if 'draft.goldwing.org.au' in line:
            draft_dir = line.split()[-1]
            if 'public_html' in line:
                 pass
            break
        elif 'draft' in line:
            draft_dir = line.split()[-1]

    # Check public_html explicitly just in case
    print("\npublic_html listing:")
    try:
        lines_ph = []
        ftp.retrlines('LIST public_html', lines_ph.append)
        for line in lines_ph:
            if 'draft.goldwing.org.au' in line:
                draft_dir = 'public_html/' + line.split()[-1]
                break
            elif 'draft' in line:
                draft_dir = 'public_html/' + line.split()[-1]
            print(line)
    except:
        pass
        
    print(f"\nTargeting draft dir: {draft_dir}")
    
    # Let's verify we found the dir and upload
    def upload_file(local_path, remote_path):
        try:
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            print(f"> Uploaded {local_path} to {remote_path}")
        except Exception as e:
            print(f"! Failed to upload {local_path}: {e}")

    # Now upload the fixes
    upload_file('app/Services/LoginRateLimiter.php', f'{draft_dir}/app/Services/LoginRateLimiter.php')
    upload_file('app/Services/EmailOtpService.php', f'{draft_dir}/app/Services/EmailOtpService.php')
    print("Done")
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
