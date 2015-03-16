<?php

class Files_sftp_test extends CIUnit_TestCase
{
    public function setUp()
    {
        $this->CI->load->driver('files');
    }
    
    public function test_connect()
    {
        // GDaemon Test
        $this->CI->files->set_driver('sftp');
        
        $config = array(
			'hostname' => 'localhost',
			'port' 		=> 22,
			'username' => 'travis',
			'password' => '1234',
		);

		$this->assertTrue($this->CI->files->connect($config));
		
		var_dump($this->CI->files->list_files('/home/travis/build/ET-NiK/GameAP/Files/'));
    }

    public function test_upload()
    {
		$this->assertTrue($this->CI->files->upload(TESTSPATH . 'upload_file.txt', '/home/travis/build/ET-NiK/GameAP/Files/upload_file.txt'));
		$this->assertTrue(($this->CI->files->file_size('/home/travis/build/ET-NiK/GameAP/Files/upload_file.txt') > 0));
	}
	
	public function test_read_file()
	{
		$this->assertEquals('FILE_CONTENTS', trim($this->CI->files->read_file('/home/travis/build/ET-NiK/GameAP/Files/File02.txt')));
		$this->assertEquals('UPLOAD_CONTENTS', trim($this->CI->files->read_file('/home/travis/build/ET-NiK/GameAP/Files/upload_file.txt')));
	}
	
	public function test_write_file()
	{
		$this->assertTrue($this->CI->files->write_file('/home/travis/build/ET-NiK/GameAP/Files/File01.txt', 'WRITED'));
		$this->assertEquals('WRITED', trim($this->CI->files->read_file('/home/travis/build/ET-NiK/GameAP/Files/File01.txt')));
		
		$this->assertTrue($this->CI->files->write_file('/home/travis/build/ET-NiK/GameAP/tests/GDaemon/Files\File01.txt', 'WRITED01'));
		$this->assertEquals('WRITED01', trim($this->CI->files->read_file('/home/travis/build/ET-NiK/GameAP/Files/File01.txt')));
	}
	
	public function test_download()
	{
		$this->assertInternalType('bool', $this->CI->files->download('/home/travis/build/ET-NiK/GameAP/Files/upload_file.txt', TESTSPATH . 'download_file.txt'));
		$this->assertTrue(file_exists(TESTSPATH . 'download_file.txt'));
		
		$this->assertEquals(filesize(TESTSPATH . 'upload_file.txt'), filesize(TESTSPATH . 'download_file.txt'));
		
		unlink(TESTSPATH . 'download_file.txt');
	}
	
	public function test_file_size()
	{
		//~ $this->assertEquals(13, $this->CI->files->file_size('/home/travis/build/ET-NiK/GameAP/Files/File02.txt'));
	}

}
 