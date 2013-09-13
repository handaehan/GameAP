<?php

class Rcon extends CI_Driver_Library {

	public $valid_drivers;
    public $CI;
    
    var $host;
	var $port;
	var $password;
	var $engine;
	var $engine_version;
	var $rcon_connect;
	
	var $errors = FALSE;
    
    function __construct()
    {
        $this->CI =& get_instance();
        $this->valid_drivers = array('rcon_goldsource', 'rcon_source', 'rcon_minecraft');
    }
    
    // ----------------------------------------------------------------
    
    /**
     * Задать значения хоста, порта, пароля, движка, версии движка
     * 
     * 
    */
    function set_variables($host, $port, $password, $engine, $engine_version = 1)
    {
		$this->host 			= $host;
		$this->port 			= $port;
		$this->password 		= $password;
		$this->engine 			= strtolower($engine);
		$this->engine_version 	= strtolower($engine_version);
	}
	
	// ----------------------------------------------------------------
	
	/**
	 * Соединение с сервером
	 * 
	*/
	function connect()
	{
		$engine = $this->engine;
		
		if (FALSE == in_array('rcon_' . $this->engine, $this->valid_drivers)) {
			$this->errors = 'Driver' . $this->engine . ' not found';
			return FALSE;
		}
		
		$this->rcon_connect = $this->$engine->connect();
		
		return (bool)$this->rcon_connect;
	}
	
	// ----------------------------------------------------------------
	
	/*
	 * Отправка rcon команды на сервер
	 * 
	 * @param string
	 * @return string
	*/
	function command($command)
	{
		$engine = $this->engine;
		
		if (!$this->rcon_connect) {
			$this->errors = 'Could not connect to server';
			return FALSE;
		}

		$rcon_string = $this->$engine->command($command);
		
		return $rcon_string;
	}
	
	// ----------------------------------------------------------------
	
	/*
	 * Получение списка игроков на сервере
	 * 
	*/
	function get_players()
	{
		$engine = $this->engine;
		return $this->$engine->get_players();
	}
	
	// ----------------------------------------------------------------
	
	/*
	 * Получение списка карт
	 *  
	*/
	function get_maps()
	{
		$engine = $this->engine;
		return $this->$engine->get_maps();
	}
	
}
