<?php
	class AscioTransport implements IRegistryTransport 
	{
		private $DumpTraffic;
		
		public function __construct(DataForm $ConnectionConfig)
		{
			if (! $ConnectionConfig instanceof DataForm)
				throw new Exception(_("ConnectionConfig must be an instance of DataForm"));
				
			foreach ($ConnectionConfig->ListFields() as $field)
				$this->{$field->Name} = $field->Value;
		}

		public function SetDumpTraffic ($bool) 
		{
			$this->DumpTraffic = $bool;
		}			
		
		/**
		 * This method must establish connection with remote registry
		 * 
		 * @return bool True on success
		 * @throws Exception 
		 */
		function Connect ()
		{
			return true;
		}
		
		/**
		 * This method must login to remote registry
		 *
		 * @return bool True on success
		 * @throws Exception
		 */
		function Login ()
		{

			return true;

		}
		
		/**
		 * This method performs request to remote registry  
		 *
		 * @param string $command Registry command
		 * @param array $data Command dependent data
		 * @return TransportResponse
		 */
		function Request ($command, $data = array())
		{
			$request = http_build_query($data);
			Log::Log(sprintf("Sending request: %s", $request), E_USER_NOTICE);
			 if ($this->DumpTraffic)
			{
				print ">> Sending request:\n";
				print "{$request}\n";				
			}
			$wsdl = $this->ServerHost;
			$client = new SoapClient($wsdl,array( "trace" => 1 ));

			$session = array(
                     	'Account'=> $this->Login,
                     	'Password' => $this->Password
                   	); 
			$sessId = array('session' => $session);
			//get sessionID first
			$getId = $client->__call('LogIn', array('parameters' => $sessId));
			// inject real sessionId into array
                        $data['sessionId'] = $getId->sessionId;
			$result=$client->__call($command, array('parameters' => $data));
			$ResponseProperty=$command."Result";
			$Response=$result->$ResponseProperty;
			$Response2=$result;

			// jumps to if $e if soap client sends fault message.
			$e =  is_soap_fault($result);

			// Log response
			Log::Log(sprintf("Server response:\n%s", $Response->Message), E_USER_NOTICE);
			if ($this->DumpTraffic)
			{
				print "<< Server respond:\n";
				print "{$Response->Message}\n";
			}
			
			
			if ($e)
				throw new Exception($e);
				
			if (!$Response)
				throw new Exception(_("Registry returned malformed answer"));
			
			if ($Response->ResultCode)
			{
				$response_code = (int)$Response->ResultCode;

				// Succes when no error messages and RRP code is successful 
				$is_success = !$errmsg && ((int)$response_code >= 200 && (int)$response_code <= 220);
				
				if (!$is_success && !$errmsg)
				{
					// Set error message
					$errmsg = $Response->Message;
				}
			}
			else
			{
				$response_code = 1;
				$is_success = !$errmsg;
			}
	
			return new TransportResponse($response_code, $Response2, $is_success, $errmsg);
		}
		
		/**
		 * This method close connection with remote registry.
		 * (Send logout request, close socket or something else implementation specific)
		 * 
		 * @return bool 
		 */
		function Disconnect ()
		{
			
		}
		
		/**
		 * Returns True if transport is connected to remote registry
		 *  
		 * @return bool
		 */
		function IsConnected()
		{
			
		}
	}
?>
