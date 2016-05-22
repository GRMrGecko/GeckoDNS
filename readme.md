#GeckoDNS

I needed a Dynamic DNS service of my own, so I wrote a quick one. This uses nsupdate with keys to update DNS records with current IP addresses. It uses simple username and password authentication over http. If you want security, you should run updates over TLS. The backend authenticates updates with the DNS server via TSGN signing.

##Instructions

###Modify `index.php` to your liking by making sure values are set right.
Mainly verify nsupdatePath is correct.

###Configure Bind9 to allow updating for the zone you need Dynamic DNS for.

Change directory to the location where your named.conf is located.

Generate a key for updating.
```
# dnssec-keygen -r /dev/urandom -a HMAC-MD5 -b 512 -n HOST example.com
Kexample.com.+157+42278
```

Read the file outputted in a text editor and copy the key value.
```
# cat Kexample.com.+157+42278.private
Private-key-format: v1.3
Algorithm: 157 (HMAC_MD5)
Key: pbrxTbsWVoE8ys0529iKpLUHQzH389rmx39yxvsgvltVABGzR4hyHzycJnlFVMuBpPJI8qO8awma5PCEfDgu/Q==
Bits: AAA=
Created: 20160522202756
Publish: 20160522202756
Activate: 20160522202756
```

Place the key in a new file in the following format.
```
# nano example.com.key
key "example.com." {
	algorithm hmac-md5;
	secret "pbrxTbsWVoE8ys0529iKpLUHQzH389rmx39yxvsgvltVABGzR4hyHzycJnlFVMuBpPJI8qO8awma5PCEfDgu/Q==";
};
```

Edit named.conf and add the following:
```
# nano named.conf
include "example.com.key";

zone "example.com" {
	type master;
	file "/var/named/example.com.hosts";
	allow-update { key "example.com."; };
};
```

The important part is `allow-update { key "example.com."; };` which allows anything which signs a message with that key to update the zone.

###Upload GeckoDNS to a web server which has access to running nsupdate.

###Add your zone key file in the `keys` folder of GeckoDNS.

###Visit https://website.com/GeckoDNS/?passwd to generate a hash of your password for updating dns.

###Create user account with details.
```
# sqlite3 databases/main.db
sqlite> INSERT INTO "users" VALUES('username','7589bc703af770d39785d714c1e32ec49145031885a8ac4a2e5f5001b9f2ec85ef274fd962e88ab0d85ff55aae90bb3be873c347eb75a1099c051d04569574fac24dbb071da6b7868182f0715885145f',1,'127.0.0.1','example.com.key','example.com.','example.com.,subdomain.example.com.',NULL,NULL,NULL,NULL);
```

###Configure nginx (or apache) to block access to private folders.
Nginx config, I'm not writing Apache config. Sorry.
```
location ~* /GeckoDNS/(databases|keys) {
	deny all;
}
```

###Copy and configure cron `updateGeckoDNS.sh` to have proper username/password/update urls on computer you want to update the DNS.

###Configure cronjob on the computer you want to update the DNS.

```
# crontab -e
*/30 * * * * /bin/bash /usr/local/bin/updateGeckoDNS.sh >/dev/null 2>&1
```

##Final words
I do not know if I would ever update this from this point, however there are some things in place so I can add on to the system. Maybe in the future it will be designed more of a service than a personal Dynamic DNS system. A good thing to change would be to separate zones from users to allow multiple zones per account and custom TTL per host.