#/bin/bash

#Username and password.
username="username"
password="password"

#The ipv4 subdomain needs to be created if you have both IPv6 and IPv4 on the website.
#The subdomain isn't a requirement.
#You can do https://website.com/GeckoDNS/ if you don't have the subdomain made.
ipv4update="https://ipv4.website.com/GeckoDNS/"

#Lave blank if you do not do IPv6.
ipv6update="https://ipv6.website.com/GeckoDNS/"

if [ ! -z "$ipv4update" ]; then
	curl -4 "$ipv4update?username=$username&password=$password"
	echo ""
fi

if [ ! -z "$ipv6update" ]; then
	curl -6 "$ipv6update?username=$username&password=$password"
	echo ""
fi