<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>QIWI PHP tester</title>
</head>
<?php

/**
 * Простой скрипт для проверки работы классов.
 *
 *
 */

require_once('qiwi-config.php');
require_once('qiwi.php');

/*
 * Если мы не используем автоопределение модулей PHP, доступных нам,
 * то просто создаем класс и настраиваем его.
 * 
 * Если нужно определить модули автоматически, то делаем так:
 *
 *
 * $q = QIWI::getInstance($qiwiConfig);
 *
 */
//$q = new QIWI($qiwiConfig);
//$q->setEncrypter(new QIWIMcryptEncrypter());
//$q->setRequester(new QIWICurlRequester());

$q = QIWI::getInstance($qiwiConfig);


echo '<h1>Параметры шифрования</h1>';
echo '<p>Значения этих параметров должны быть такими же как в тестилке QIWI</p>';
echo 
'<table>' .
'<tr><td>passwordMD5</td><td>' . bin2hex($q->passwordMD5) . '</td></tr>' .
'<tr><td>salt</td><td>' . bin2hex($q->salt) . '</td></tr>' .
'<tr><td>key</td><td>' . bin2hex($q->key) . '</td></tr>' .
'</table>';

function qt($str){ 
  return htmlspecialchars($str);
}

echo "\n\n";

?>

<?php
if (empty($_POST['text_to_encrypt'])) {
  $text = "testtest";
} else {
  $text = $_POST['text_to_encrypt'];
}
?>

<h1>Шифруем строку</h1>
<form method="post">
  <textarea name="text_to_encrypt"><?=$text?></textarea><br/>
  <input type="submit" value="Зашифровать"/>
</form>
<?php
echo '<pre>';
echo qt($q->encrypt($text));
echo '</pre>';
?>



<h1>Запрос баланса</h1>
<?php
echo '<pre>';
echo qt($q->ping());
echo '</pre>';
?>



<h1>Создание счёта</h1>
<?php
echo "<pre>";
try {
  if ($q->createBill(array(
			       'phone' => '903#######',
			       'amount' => 0.01,
			       'comment' => 'TEST PHP',
			       'txn-id' => '1'
			       )
		     )) {
    echo "OK";
  } else {
    echo "FAIL";
  }

} catch (QIWIMortalCombatException $e) {
  echo 'Failed: ' . $e->code . ', ' . ($e->fatality?"true":"false") . ', ' . QIWI::$errors[$e->code];
}
echo '</pre>';
?>


<h1>Статус счёта</h1>
<table>
<tr><th>Номер счёта</th><th>Сумма</th><th>Статус</th><th>Статус по-человечески</th></tr>
<?php
echo "<table>";
foreach($q->billStatus(1,FALSE) as $id=>$v) {
  $human_status = QIWI::$statuses[''.$v['status']];
  echo "<tr><td>$id</td><td>{$v['amount']}</td><td>{$v['status']}</td><td>{$human_status}</td></tr>";
}
?>
</table>


<h1>Отмена счёта</h1>
<?php
echo "<pre>";
echo qt($q->cancelBill("1"));
echo "</pre>";
?>
