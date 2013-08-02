<?php

namespace F8;

use Sluggo\Sluggo;

abstract class Router {

    public $url;
    public $controller;
    public $uc_controller;
    public $action;
    public $vars = array();
    public $seo;

    public $rawurl;				// The raw url from the request uri (includes query)
    public $rawurlnoquery;		// The raw url with the query string removed.

    public $logger;             // A PSR-3 compatible logger (Required)
    public $viewSwitch;         // The ViewSwitch for handling different view types
    public $connection;         // A db connection, most likely MongoDB (Optional)

    public $debug = 0;          // 0 = none, 1 = critical, 2 = info

    public $appNamespace = 'App';

    abstract public function getConnection(&$errors);

    public function __construct(\Psr\Log\LoggerInterface $logger, \F8\ViewSwitch $viewSwitch){
        $this->logger = $logger;
        $this->viewSwitch = $viewSwitch;

        $this->rawurl = $_SERVER['REQUEST_URI'];
        $this->rawurlnoquery = strpos($this->rawurl, '?') === false ? $this->rawurl : substr($this->rawurl, 0, strpos($this->rawurl, '?'));
    }

    public function go() {
        $this->parseRoute($this->rawurlnoquery)->verifyRoute($errors);
        $data = $this->followRoute($errors);
        $this->viewSwitch->go($this, $data, $errors);
    }

    public function parseRoute($url) {
        $this->url = $url;

        $urlParts = explode('/', $url);

        if ($urlParts[0] == '') array_shift($urlParts); // If the url starts with a / then the first part will be empty.

        // Treat the first two parts differently
        $controllerCandidate = array_shift($urlParts);
        if (!$this->storeVar($controllerCandidate)) {
            $this->controller = $controllerCandidate;
            $actionCandidate = array_shift($urlParts);
            if (!$this->storeVar($actionCandidate)) {
                $this->action = $actionCandidate;
            }
        }

        foreach ($urlParts as $part) {
            if (!$this->storeVar($part)) {
                if ($this->seo) {
                    $this->seo .= '/'.$part;
                } else {
                    $this->seo = $part;
                }
            }
        }

        if (empty($this->controller)) $this->controller = 'index';
        if (empty($this->action)) $this->action = 'view';

        $this->uc_controller = ucfirst($this->controller);

        return $this;
    }

    /**
     * If the part is in named variable format (name:value), then add it to $this->vars and return true.
     * Otherwise, return false.
     *
     * @param string $part
     * @return bool
     */
    function storeVar($part){
        if ($pos = strpos($part, ':')) { // This is OK, becasue it must have at least 1 character before the colon.
            $key = substr($part, 0, $pos);
            $value = substr($part, $pos + 1);
            $this->vars[$key] = rawurldecode($value);
            return true;
        } else {
            return false;
        }
    }

    function getVar($var){
        if (isset($this->vars[$var])) {
            return $this->vars[$var];
        } else {
            return null;
        }
    }


    /**
     * @param array $errors
     * @return \F8\Router $this
     */
    public function verifyRoute(& $errors){
        if (!is_array($errors)) $errors = array();

        if (!class_exists($this->appNamespace.'\\Controller\\'.$this->uc_controller, true)) {
            $errors[] = array('code'=>'1000', 'message'=>sprintf(\_('%s not found.'), $this->uc_controller));
            $this->controller = 'Error';
            $this->action = '_404';
            $this->vars = array('errors' => $errors);
            return $this;
        }
        if (!in_array('F8\\Controller', class_implements($this->appNamespace.'\\Controller\\'.$this->uc_controller, true))) {
            $errors[] = array('code'=>'1001', 'message'=>sprintf(\_('%s is not a controller.'), $this->uc_controller));
            $this->controller = 'Error';
            $this->action = '_404';
            $this->vars = array('errors' => $errors);
            return $this;
        }
        if (!is_callable(array($this->appNamespace.'\\Controller\\'.$this->uc_controller, $this->action))) {
            $errors[] = array('code'=>'1002', 'message'=>sprintf(\_('%s action does not exist.'), $this->action));
            $this->controller = 'Error';
            $this->action = '_404';
            $this->vars = array('errors' => $errors);
            return $this;
        }

        return $this;
    }

    public function followRoute(& $errors){
        $cName = $this->appNamespace.'\\Controller\\'.$this->uc_controller;
        $c = new $cName();
        $a = $this->action;
        return $c->$a($this);
    }

    public function makeRelativeURL($controller, $action, array $vars, $seo = "") {
        $url = '/'.$controller.'/'.$action;
        foreach($vars as $key=>$value) {
            $url .= '/'.$key.':'.rawurlencode($value);
        }
        if ($seo) {
            $sluggo = new Sluggo($seo);
            $url .= '/'.$sluggo->getSlug();
        }
        return $url;
    }

    /**
     * This converts only public methods of an object into an associative array. It does so recursively and is suitable
     * to prepare objects for insertion into MongoDB. (MongoDB will throw an error if objects have private or protected
     * properties.)
     *
     * @param $object
     * @return array
     */
    public function objectToArray( $object ) {
        if( !is_object( $object ) && !is_array( $object ) ) {
            return $object;
        }
        if( is_object( $object ) ) {
            if (strpos(get_class($object), 'Mongo') === 0) return $object;
            $object = get_object_vars($object);
        }
        return array_map( array( $this, 'objectToArray'), $object );
    }

}