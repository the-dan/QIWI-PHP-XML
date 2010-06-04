<?php

/**
 *
 * TODO: introduce special extra with software
 * TODO: beeline
 * TODO: pluggable XML parsers
 * TODO: pluggable logging
 * TODO: replace one-method classes with callbacks?
 *  
 *
 */

// TODO: exceptions handling
class QIWIBitrixRequester {
  function request($url, $body) {
    // TODO: check errno and result
    $errno = "";
    $errstr = "";
    $info = parse_url($url);
    // TODO: check for false
    return QueryGetData($info['host'], 443, $info['path'], $body, $errno, $errstr, "POST", "ssl://");
  }
}

// TODO: exceptions handling
class QIWICurlRequester {
  function request($url, $body) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type' => 'text/xml; encoding=utf-8'));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    
    $result = curl_exec($ch);
    
    curl_close($ch);
    
    return $result;
    
  }
}

class QIWISocketRequester {
  function request($url, $body) {
  }
}

class QIWIMcryptEncrypter {
  function encrypt($message, $key) {
    return  mcrypt_encrypt(MCRYPT_3DES, $key, $message, MCRYPT_MODE_ECB, "\0\0\0\0\0\0\0\0");
  }
}

class QIWINativeEncrypter {
  function encrypt($message, $key) {
    return $message;
  }
}


class QIWIMortalCombatException extends Exception{
  var $fatality = FALSE;
  var $code = 0;
  function __construct($code, $fatality) {
    $this->code = $code;
    $this->fatality = $fatality;
  }
}

class QIWI {

  static function getInstance($config) {
    $q = new QIWI($config);

    if (function_exists('curl_init')) {
      $q->setRequester(new QIWICurlRequester());
    } else if (function_exists('QueryGetData')) {
      $q->setRequester(new QIWIBitrixRequester());
    } else {
      $q->setRequester(new QIWISocketRequester());
    }

    if (function_exists('mcrypt_encrypt')) {
      $q->setEncrypter(new QIWIMcryptEncrypter());
    } else {
      $q->setEncrypter(new QIWINativeEncrypter());
    }

    if (!function_exists('simplexml_load_string')) {
      return NULL;
    }

    return $q;
  }

  static $statuses = array(
			   '50' => 'Неоплаченный счёт',
			   '60' => 'Оплаченный счёт',
			   '150' => 'Счёт отклонён'
			   );

  static $errors = array(
		      300 => 'Неизвестная ошибка',
		      13 => 'Сервер занят. Повторите запрос позже',
		      150 => 'Неверный логин или пароль',
		      215 => 'Счёт с таким номером уже существует',
		      278 => 'Превышение максимального интервала получения списка счетов',
		      298 => 'Агент не существует в системе',
		      330 => 'Ошибка шифрования',
		      370 => 'Превышено макс. кол-во одновременно выполняемых запросов',
		      0 => 'OK'
		      );

  var $passwordMD5;
  var $salt;
  var $key;
  var $id;
  var $encrypter;
  var $requester;
  var $config;

  function __construct($config) {
    $this->config = $config;
    $password = $config['password'];
    $this->id = $config['shopID'];
    $this->passwordMD5 = md5($password, TRUE);
    $this->salt = md5($this->id . bin2hex($this->passwordMD5), TRUE);
    $this->key = str_pad($this->passwordMD5, 24, '\0');

    for ($i = 8; $i < 24; $i++) {
      if ($i >= 16) {
	$this->key[$i] = $this->salt[$i-8];
      } else {
	$this->key[$i] = $this->key[$i] ^ $this->salt[$i-8];
      }
    }
  }
  
  function setEncrypter($obj) {
    $this->encrypter = $obj;
  }

  function setRequester($obj) {
    $this->requester = $obj;
  }


  function encrypt($message) {
    if (!$this->config['encrypt']) {
      return $message;
    }
    $n = 8 - strlen($message) % 8;      
    $pad = str_pad($message, strlen($message) + $n, ' ');
    $crypted = $this->encrypter->encrypt($pad, $this->key);
    $result = "qiwi" . str_pad($this->id, 10, "0", STR_PAD_LEFT) . "\n";
    $result .= base64_encode($crypted);
    return $result;
  }

  function check_response($xml) {
    $rc = $xml->{'result-code'};
    $fatality = $rc['fatal'];
    if ($rc != 0) {
      throw new QIWIMortalCombatException((string)$rc, $fatality=='true');
    } else {
      return TRUE;
    }
  }

  function request($url, $body) {
    return $this->requester->request($url, $body);
  }

  
  /**
   * Создание счёта.
   *
   * Обязательные параметры
   *
   *  {
   *    phone    номер телефона того, кому выставляется счёт. 10 цифр, без +7 или 8 вначале.
   *    amount   сумма выставляемого счёта.
   *    comment  комментарий
   *    txn-id   номер счёта. некий *уникальный* номер, например, номер заказа в интернет-магазине
   *  }
   * 
   * Опциональные параметры. Если не указывать, будут использоваться
   * соответствующие параметры из конфигурации.
   * Описание см. в qiwi-config.sample.php.

   * {
   *   create-agt
   *   lifetime
   *   alarm-sms
   *   alarm-call
   *   TODO:from-provider
   * }
   *
   * @param $options ассоциативный массив с параметрами создания счёта
   *
   **/
  function createBill($options, $ignorePrefix=FALSE) {
    $defaults = array(
		      'create-agt' => $this->config['create-agt'],
		      'lifetime' => $this->config['lifetime'],
		      'alarm-sms' => $this->config['alarm-sms'],
		      'alarm-call' => $this->config['alarm-call']
		      );
    $options = array_merge($defaults, $options);
    $x = '<?xml version="1.0" encoding="utf-8"?><request>';
    $x .= '<protocol-version>4.00</protocol-version>';
    $x .= '<request-type>30</request-type>';
    $x .= '<extra name="password">' . $this->config['password'] . '</extra>';
    $x .= '<terminal-id>' . $this->id . '</terminal-id>';
    $x .= '<extra name="txn-id">' . ($ignorePrefix ? $options['txn-id'] : $this->config['txn-prefix']) . $options['txn-id'] . '</extra>';
    $x .= '<extra name="to-account">' . $options['phone'] . '</extra>';
    $x .= '<extra name="amount">' . $options['amount'] . '</extra>';
    $x .= '<extra name="comment">' . $options['comment'] . '</extra>';
    $x .= '<extra name="create-agt">' . $options['create-client'] . '</extra>';
    $x .= '<extra name="ltime">' . $options['lifetime'] . '</extra>';
    $x .= '<extra name="ALARM_SMS">' . $options['alarm-sms'] . '</extra>';
    $x .= '<extra name="ACCEPT_CALL">' . $options['alarm-call'] . '</extra>';
    $x .= '</request>';

    if ($this->config['log']) {
      echo $x;
      echo $this->encrypt($x);
    }

    $r = $this->request($this->config['url'], $this->encrypt($x));

    $xml = simplexml_load_string('<?xml version="1.0" encoding="utf-8"?>'.$r);
    
    $this->check_response($xml);
    
    return TRUE;
  }

  /**
   * Запрос сатусов счетов.
   *
   * Запрашивает статусы счетов с заданными txn-id.
   * По умолчанию добавляет перед каждым txn-id префикс, заданный
   * в конфигурации. Это сделано для того чтобы разделять
   * счета магазина, выставленные, вручную через
   * форму и автоматически через данный модуль.
   *
   * Опциональные параметр $ignorePrefix позволяет
   * запросить счёт с тем ID, который виден
   * в отчетах на ishop.qiwi.ru
   * 
   * 
   * @param $ids array, string идентификаторы счетов
   * @param $ignorePrefix не использовать префикс, заданный в конфигурации
   */
  function billStatus($ids, $ignorePrefix = FALSE) {
    $bills = array();
    if (is_int($ids)) {
      $bills[] = $ids;
    } else {
      $bills = $ids;
    }
    if (empty($bills)) {
      return TRUE;
    }
    $x = '<?xml version="1.0" encoding="utf-8"?><request>';
    $x .= '<protocol-version>4.00</protocol-version>';
    $x .= '<request-type>33</request-type>';
    $x .= '<extra name="password">' . $this->config['password'] . '</extra>';
    $x .= '<terminal-id>' . $this->id . '</terminal-id>';
    $x .= '<bills-list>';
    foreach($bills as $txnID) {
      $x .= '<bill txn-id="' . ($ignorePrefix?"":$this->config['txn-prefix']) . $txnID . '"/>';
    }
    $x .= '</bills-list>';
    $x .= '</request>';

    if ($this->config['log']) {
      //      echo $x;
      //      echo $this->encrypt($x);
    }

    $r = $this->request($this->config['url'], $this->encrypt($x));

    if ($this->config['log']) {
      //echo $r;
    }

    $xml = simplexml_load_string('<?xml version="1.0" encoding="utf-8"?>'.$r);

    $this->check_response($xml);

    $result = array();
    // TODO: check if empty?
    /*if ($xml->{'bills-list'}) {
      }*/
    foreach ($xml->{'bills-list'}->children() as $bill) {
      $result[(string)$bill['id']] = array(
			'status' => $bill['status'],
			'amount' => $bill['sum']
			);
    };
    return $result;
  }

  function cancelBill($id, $ignorePrefix=FALSE) {
    $x = '<?xml version="1.0" encoding="utf-8"?><request>';
    $x .= '<protocol-version>4.00</protocol-version>';
    $x .= '<request-type>29</request-type>';
    $x .= '<extra name="password">' . $this->config['password'] . '</extra>';
    $x .= '<terminal-id>' . $this->id . '</terminal-id>';
    $x .= '<extra name="txn-id">' . ($ignorePrefix ? $id : $this->config['txn-prefix'] . $id ). '</extra>';
    $x .= '<extra name="status">reject</extra>';
    $x .= '</request>';

    $r = $this->request($this->config['url'], $this->encrypt($x));

    if ($this->config['log']) {
      echo $r;
    }

    $xml = simplexml_load_string('<?xml version="1.0" encoding="utf-8"?>'.$r);

    $this->check_response($xml);

    return TRUE;
  }

  // TODO: что будет, если вернется несколько экстра-полей?
  function ping() {
    $x = '<?xml version="1.0" encoding="utf-8"?><request>';
    $x .= '<protocol-version>4.00</protocol-version>';
    $x .= '<request-type>3</request-type>';
    $x .= '<extra name="password">' . $this->config['password'] . '</extra>';
    $x .= '<terminal-id>' . $this->id . '</terminal-id>';
    $x .= '</request>';
    $r = $this->request($this->config['url'], $this->encrypt($x));

    $xml = simplexml_load_string('<?xml version="1.0" encoding="utf-8"?>'.$r);

    $this->check_response($xml);
    
    return $xml->extra;

  }

}

?>
