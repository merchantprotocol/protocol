#!/bin/sh

upSeconds="$(/usr/bin/cut -d. -f1 /proc/uptime)"
secs=$((${upSeconds}%60))
mins=$((${upSeconds}/60%60))
hours=$((${upSeconds}/3600%24))
days=$((${upSeconds}/86400))
UPTIME=`printf "%d days, %02dh%02dm%02ds" "$days" "$hours" "$mins" "$secs"`

# get the load averages
read one five fifteen rest < /proc/loadavg

echo "


$(tput setaf 2)
                            __  __               _                 _
             ZZZZZ         |  \/  |             | |               | |
        ZZZZZZZZZZZZZZZ    | \  / | ___ _ __ ___| |__   __ _ _ __ | |_
        ZZZZZ ZZZZZZZZZ    | |\/| |/ _ \ '__/ __| '_ \ / _\` | '_ \| __|
        ZZZZ ZZZZZZZZZZ    | |  | |  __/ | | (__| | | | (_| | | | | |_
        ZZI          ZZ    |_|  |_|\___|_|  \___|_| |_|\__,_|_| |_|\__|
        ZZZZZZZZZZZZZZZ
        ZZZ          ZZ     _____           _                  _
         ZZZZZZZZZZ ZZZ    |  __ \         | |                | |
         ZZZZZZZZZ ZZZ     | |__) | __ ___ | |_ ___   ___ ___ | |
           ZZZZZZZZZZ	   |  ___/ '__/ _ \| __/ _ \ / __/ _ \| |
            ZZZZZZZZ	   | |   | | | (_) | || (_) | (_| (_) | |
              ZZZZ         |_|   |_|  \___/ \__\___/ \___\___/|_|
$(tput setaf 7)

            This instance was configured by MerchantProtocol.com
                           Your Ecommerce Partner


      ----------------------------------------------------------------

      You have entered an Official Merchant Protocol System, which may be used only
      for authorized purposes. The company may monitor and audit usage of this system,
      and all persons are hereby notified that use of this system constitutes consent
      to such monitoring and auditing. Unauthorized attempts to upload information
      and/or change information on these computers are strictly prohibited and are
      subject to prosecution under the Computer Fraud and Abuse Act of 1986 and
      Title 18 U.S.C. Sec.1001 and 1030. 

      Merchant Protocol, LLC
      9169 W State St #701
      Garden City, ID, 83714

      merchantprotocol.com

      ----------------------------------------------------------------

      Distro     : `uname -srmo`
      Date       : `date +"%A, %e %B %Y, %r"`

      Webroot    : ${PROTOCOL_WEBROOT}
      PHP        : PHP $(php -v|grep --only-matching --perl-regexp "\\d+.\\d+\.\\d+"|head -n1)

      Uptime     : ${UPTIME}
      Memory     : `cat /proc/meminfo | grep MemFree | awk {'print $2'}`kB (Free) / `cat /proc/meminfo | grep MemTotal | awk {'print $2'}`kB (Total)
      Swap       : `free -m | tail -n 1 | awk {'print $2'}`M
      Disk       : `df -h / | awk '{ a = $2 } END { print a }'` free of `df -h / | awk '{ a = $2 } END { print a }'`
      CPU        : `cat /proc/cpuinfo | grep 'model name' | head -1 | cut -d':' -f2`
      Load Avgs  : ${one}, ${five}, ${fifteen} (1, 5, 15 min)
      Processes  : `ps ax | wc -l | tr -d " "` running
      IP Address : private `ip a | grep glo | awk '{print $2}' | head -1 | cut -f1 -d/`; public `wget -q -O - http://icanhazip.com/ | tail`

"