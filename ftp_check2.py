import ftplib
try:
    ftp = ftplib.FTP_TLS('ftp.goldwing.org.au')
    ftp.login('goldwing', 'aga@wagga@')
    ftp.prot_p()
    ftp.set_pasv(True)
    if ftp.cwd('public_html/draft.goldwing.org.au'):
        print("In public_html/draft.goldwing.org.au")
    
    print("Files in target dir:")
    ftp.dir()
    
    ftp.quit()
except Exception as e:
    print(f"FTP Error: {e}")
