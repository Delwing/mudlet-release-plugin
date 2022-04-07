from posixpath import dirname
from zipfile import ZipFile
import os
import sys
from datetime import datetime

def addDirToZip(zipObj, dirName):
    for folderName, subfolders, filenames in os.walk(dirName):
        for filename in filenames:
            filePath = os.path.join(folderName, filename)
            basePath = os.path.relpath(filePath, dirName)
            zipObj.write(filePath, "mudlet-release/" + dirName + "/" + basePath)

with ZipFile("mudlet-release.zip", "w") as zipObj:
    addDirToZip(zipObj, "vendor")
    zipObj.write("mudlet-release.php", "mudlet-release/mudlet-release.php")


version = sys.argv[1]
now = datetime.now()

with open('info.template.json', "r+") as text_file:
        texts = text_file.read()
        texts = texts.replace("@version@", version)
        texts = texts.replace("@date@", now.strftime("%Y-%m-%d %H:%M:%S"))
        text_file.close()
with open('info.json', "w") as text_file:
    text_file.write(texts)

with open('mudlet-release.php', "r+") as text_file:
    texts = text_file.read()
    texts = texts.replace("@version@", version)
    print(texts)
    text_file.close()
with open('mudlet-release.php', "w") as text_file:
    text_file.write(texts)

