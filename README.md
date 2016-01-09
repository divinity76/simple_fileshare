# simple_fileshare
simple fileshare site written in php...
it is written with security in mind, it is simple, has a decent API,
using an SQLite database,
supporting file deduplication, public and hidden files, password protected uploads, expiration dates,
and is licensed under unlicense.org 



#requirements
php 64bit >= 5.5.0 with sqlite (older versions will probably work, but not tested)

(the 64bit is only required if you want to support file expiration dates above 03:27 AM, 19 january, 2038, 
and can be fixed by using the bcmath extension. the 64bit edition support expiration dates overs 290 billion years AD )
