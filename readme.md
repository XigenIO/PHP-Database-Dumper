
# MySQL setup

```bash
mysql -h 127.0.0.1 -e "DROP USER 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "CREATE USER 'databasedumper'@'%' IDENTIFIED BY 'VgNPm8aaXkDsveWqOpfO42AU';"
mysql -h 127.0.0.1 -e "GRANT ALL ON *.* to 'databasedumper'@'%';"
mysql -h 127.0.0.1 -e "FLUSH PRIVILEGES;"
```
