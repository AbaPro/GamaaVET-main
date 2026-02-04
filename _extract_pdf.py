from pdfminer.high_level import extract_text
text = extract_text('Permissions.pdf')
open('Permissions.txt','w', encoding='utf-8').write(text)
print('ok')
