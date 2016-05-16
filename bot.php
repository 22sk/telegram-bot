<?php

/**
 * @author Samuel Kaiser <samuel.kaiser01@gmail.com>
 * @since 14.05.2016
 *
 * @property Request  $req
 * @property Response $res
 * @property array $processables
 */
class Bot {
  /** @var Request  */ private $req;
  /** @var Response */ private $res;

  /** @var array */    private $processables;

  private $me;

  public function __get($name) { return $this->$name; }

  public function me($update = false) {
    if($update or !isset($this->me)) $this->me = $this->send(new Response("getMe", array()))->result;
    return $this->me;
  }

  /**
   * Creates new Bot.
   * @param string $url
   * @param Request|stdClass $req
   */
  public function __construct($url, $req = null) {
    $this->url = $url;
    $this->req = $req instanceof Request ? $req : (isset($req) ? Request::map($req) : Request::getRequest());

    $this->register("command", "help", function($req) {
      $text = "All commands are listed below:\n";
      foreach($this->processables['command'] as $name => $value) {
        $text.="/".$name.(isset($value['help']) ? ": ".$value['help'] : '')."\n";
      }
      return Response::build($req, array(
        "text" => $text,
        "parse_mode" => "Markdown"
      ));
    }, "Prints this message");
  }


  const COMMAND = "commands",
        KEYWORD = "keywords",
        INLINE  = "inlines";
  /**
   * @param string $type Processable's class name
   * @param string|array $name
   * @param callable $callable
   * @param string|null $help
   * @return bool False if class does not exist
   */
  public function register($type, $name, $callable, $help = null) {
    if(!class_exists($type)) return false;
    if(gettype($name) != 'array') {
      $array = &$this->processables[$type];
      $array[$name]['callable'] = $callable;
      $array[$name]['help']     = $help;
    } else foreach($name as $r) $this->register($type, $r, $callable, $help);
    return true;
  }

  public function run() {
    $this->echo = array("request" => $this->req);
    // Execute process() for all classes that implement Processable
    foreach($classes = get_declared_classes() as $class) {
      $reflect = new ReflectionClass($class);
      if($reflect->implementsInterface('Processable')) {
        $res = forward_static_call(array($class, 'process'), $this);
        if($this->res = $res instanceof Response) $this->send($res);
      }
    }
    echo json_encode($this->echo, JSON_PRETTY_PRINT);
  }

  /**
   * @param Response $response
   * @return mixed
   */
  public function send($response) {
    $context = stream_context_create( array(
      'http' => array(
        // http://www.php.net/manual/de/context.http.php
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'ignore_errors' => true,
        'content' => json_encode($response->content)
      )
    ));
    $url = $this->url . $response->method;
    $result = json_decode(file_get_contents($url, false, $context));
    $i = isset($this->echo['responses']) ? count($this->echo['responses']) : 0;
    $this->echo['responses'][$i]['response'] = $response->content;
    $this->echo['responses'][$i]['result'] = $result;
    return $result;
  }
}

/** @see https://core.telegram.org/bots/api#available-methods */
class Response {
  public $method;
  public $content;

  public function __construct($method, $content) {
    $this->method = $method;
    $this->content = $content;
  }

  const REPLY_IN_GROUP = 0, TO_CHAT = 1, REPLY_TO_MESSAGE = 2, REPLY_TO_REPLIED = 3, TO_SENDER = 4;

  /**
   * @see Response::TO_CHAT, Response::TO_SENDER, Response::REPLY_TO_MESSAGE, Response::REPLY_TO_REPLIED
   * @param Request $req    The Request to generate the Response from
   * @param array   $add    Data to add to the generated Response
   * @param string  $method The API method that should be used
   * @param integer $type   Type of response (class constants)
   * @param bool    $bypass
   *   If reply_to_message is non-existent, false is returned, if bypass is set to false.
   *   Else, the original message is used instead.
   * @return Response
   */
  // TODO: Ability to build other types of responses
  public static function build($req, $add = null, $method = "sendMessage",
                               $type = Response::REPLY_IN_GROUP, $bypass = true) {
    $array = array();
    switch ($type) {
      case self::REPLY_TO_MESSAGE:
        $array['reply_to_message_id'] = $req->message->message_id;
        $array['chat_id'] = $req->message->chat->id;
        break;
      case self::REPLY_IN_GROUP:
        if($req->message->chat->type != 'private') $array['reply_to_message_id'] = $req->message->message_id;
        $array['chat_id'] = $req->message->chat->id;
        break;
      case self::REPLY_TO_REPLIED:
        if(isset($req->message->reply_to_message)) $array['reply_to_message_id'] = $req->message->reply_to_message->id;
        else if(!$bypass) return false;
        $array['chat_id'] = $req->message->chat->id;
        break;
      case self::TO_CHAT:   $array['chat_id'] = $req->message->chat->id; break;
      case self::TO_SENDER: $array['chat_id'] = $req->message->from->id; break;
    }
    $array = array_merge($array, $add);
    return new Response($method, $array);
  }
}

/** @see https://core.telegram.org/bots/api#update */
class Request {
  private function __construct() {}

  /** @var Command */
  public $command;

  /**
   * @param stdClass $json json_decode()'d API request
   * @return Request
   */
  public static function map($json) {
    $request = obj2obj($json, "Request");
    if(isset($request->message->text)) $request->command = new Command($request->message->text);
    return $request;
  }
  public static function getRequest() {
    $req = json_decode(file_get_contents("php://input"));
    if(!isset($req)) throw new Exception("No POST data sent!");
    return self::map($req);
  }
}

// http://stackoverflow.com/questions/3243900/convert-cast-an-stdclass-object-to-another-class
function obj2obj($instance, $className) {
  return unserialize(sprintf(
    'O:%d:"%s"%s',
    strlen($className),
    $className,
    strstr(strstr(serialize($instance), '"'), ':')
  ));
}

interface Processable {
  /**
   * @param Bot $bot
   * @return Response|false
   */
  public static function process($bot);
}

class Command implements Processable {
  /**
   * Used to separate a message into an Command containing all necessary information.
   * @param string $msg Message to generate the Command from.
   */
  public $valid;
  public $text, $cmd, $bot, $args;

  public function __construct($msg) {
    $keys = array('text', 'cmd', 'bot', 'args');
    // Writing the command's information into $array
    preg_match("/^\/([^@\s]+)@?(?:(\S+)|)\s?(.*)$/i", $msg, $array);
    $this->valid = false;
    if (!empty($array)) {
      // Setting object's values
      for ($i=0; $i<count($array); $i++) $this->$keys[$i] = $array[$i];
      $this->valid = true;
    }
  }

  public static function process($bot) {
    if(!isset($bot->req->message)) return false;
    if($bot->req->command->valid and array_key_exists($bot->req->command->cmd, $bot->processables['command'])
      and (empty($bot->req->command->bot) or $bot->req->command->bot == $bot->me()->username)) {
      return $bot->processables['command'][$bot->req->command->cmd]['callable']($bot->req);
    } return false;
  }
}

class Keyword implements Processable {
  private $keywords;
  public function __construct($keyword, $_) {
    foreach(func_get_args() as $word) array_push($this->keywords, $word);
  }

  public static function process($bot) {
    if(!isset($bot->req->message)) return false;
    foreach($bot->processables['keyword'] as $word => $value) {
      if(stristr($bot->req->message->text, $word)) return $value['callable']($bot->req);
    } return false;
  }
}

class Inline implements Processable {

  public static function process($bot) {
    if(empty($bot->req->inline_query)) return false;
    preg_match("/^\w+/", $bot->req->inline_query->query, $match);
    $word = $match[0];
    foreach($bot->processables['inline'] as $inline => $value) {
      if(strcasecmp($word, $inline)) return $value['callable']($bot->req);
    } if(array_key_exists('default', $bot->inlines)) {
      return $bot->processables['inline']['default']['callable']($bot->req);
    }
    return false;
  }
}

