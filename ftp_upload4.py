import ftplib
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
        
    def upload_file(local_path, remote_path):
        try:
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            print(f"> Uploaded {local_path} as {remote_path}")
        except Exception as e:
            print(f"! Failed to upload {local_path}: {e}")

    upload_file('app/Views/partials/backend_member_sidebar.php', 'app/Views/partials/backend_member_sidebar.php')
    upload_file('public_html/member/index.php', 'member/index.php')
    upload_file('public_html/migrate_dealers.php', 'migrate_dealers.php')
    print("Done uploading")
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
