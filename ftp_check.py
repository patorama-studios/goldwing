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

    if try_chdir('public_html/draft.goldwing.org.au'):
        print("IN public_html/draft.goldwing.org.au")
    elif try_chdir('draft.goldwing.org.au'):
        print("IN draft.goldwing.org.au")
    
    print("Files in current directory:")
    ftp.dir()
    
    print("Trying to go to public_html...")
    if try_chdir('public_html'):
        ftp.dir()
    
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
