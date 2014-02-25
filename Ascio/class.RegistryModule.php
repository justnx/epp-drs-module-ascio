<?php

                /**
                 * Ascio Registry Module for EPP-DRS
                 *
                 * API description: http://aws.ascio.info
                 *
                 * Github: https://github.com/justnx
                 *
                 */

	class AscioRegistryModule extends AbstractRegistryModule implements IRegistryModuleClientPollable 
	{

		private $contact_type_prefix_map = array(
			CONTACT_TYPE::ADMIN => 'contact',
			CONTACT_TYPE::TECH => 'contact',
			CONTACT_TYPE::BILLING => 'contact',
			CONTACT_TYPE::REGISTRANT => 'registrant',
		);

		private function PackContact (Contact $Contact, $as_type)
		{
			$std_fields_contact = array(
                                "FirstName",
				"LastName",
				"OrgName",
                                "Address1",
				"Address2",
				"PostalCode",
				"City",
				"State",
				"CountryCode",
				"Email",
				"Phone",
				"Fax",
				"OrganisationNumber"
                        	);

			$prefix = $this->contact_type_prefix_map[$as_type];
			
			foreach ($Contact->GetRegistryFormattedFieldList() as $fname => $fvalue)
			{
				$k = in_array($fname, $std_fields) ? "{$fname}" : $fname;
				$data[$k] = $fvalue;
			}
			return array($prefix => $data);
		}
		
		
		/**
		 * Called to validate either user filled all fields of your configuration form properly.
		 * If you return true, all configuration data will be saved in database. If you return array, user will be presented with values of this array as errors. 
		 *
		 * @param array $post_values
		 * @return True or array of error messages.
		 */
		public static function ValidateConfigurationFormData($post_values)
		{
			return true;
		}
		
	     /**
	     * Must return a DataForm object that will be used to draw a configuration form for this module.
	     * @return DataForm object
	     */
		public static function GetConfigurationForm()
		{
			$Form = new DataForm();
			$Form->AppendField( new DataFormField("ServerHost", FORM_FIELD_TYPE::TEXT, "API hostname", 1));
			$Form->AppendField( new DataFormField("Login", FORM_FIELD_TYPE::TEXT, "Ascio Login", 1));
			$Form->AppendField( new DataFormField("Password", FORM_FIELD_TYPE::TEXT , "Ascio Password", 1));
			
			return $Form;
		}

                /**
                 * Must return current Registrar ID (CLID). Generally, you can return registrar login here.
                 * Used in transfer and some contact operations to determine either object belongs to current registrar.
                 *
                 * @return string
                 */
                public function GetRegistrarID()
                {
                        return $this->Config->GetFieldByName('Login')->Value;
                }
	     /**
	     * Update domain auth code.
	     *
	     * @param Domain $domain
	     * @param string $authcode A list of changes in domain flags for the domain
	     * @return UpdateDomainAuthCodeResponse
	     */
	    public function UpdateDomainAuthCode(Domain $domain, $authCode)
	    {

	    	$params = array( 'order' => array(
			'Type' => 'Update_AuthInfo',
			'Domain' => array(
			'Comment' => 'EPP-DRS Update Authcode',
			'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
	    		'AuthInfo' => $authCode
	    	)));
	    	$Resp = $this->Request('CreateOrder', $params);
	    	
			$status = $Resp->Succeed ? 
				REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
			return new UpdateDomainAuthCodeResponse($status, $Resp->ErrMsg, $Resp->Code); 

		}
		
		/**
	     * Called to check either domain can be transferred at this time.
	     *
	     * @param Domain $domain
	     * @return DomainCanBeTransferredResponse
	     */
	    public function DomainCanBeTransferred(Domain $domain)
		{
			$params = array(
				'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
			);
			$Resp = $this->Request('GetDomain', $params);
			
			$ok = $Resp->Data->DomainStatus instanceof SimpleXMLElement;
			
			$Ret = new DomainCanBeTransferredResponse(
				$ok ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED,
				$Resp->ErrMsg,
				$Resp->Code
			);
			if ($ok)
			{
				$status = (string)$Resp->Data->DomainStatus->InAccount;
				$Ret->Result =
					// not in our database
					$status == '0' ||
					// in our database but in a different account than the one cited in this query 
					$status == '2';
			}
			else
			{
				$Ret->Result = false;
			}
			return $Ret;
		}
	    
	    
	    /**
	     * Send domain trade (change of the owner) request.
	     * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.  
	     * 
	     * @param Domain $domain Domain must have contacts and nameservers 
	     * @param integer $period Domain delegation period
	     * @param array $extra Some registry specific fields 
	     * @return ChangeDomainOwnerResponse
	     */
	    public function ChangeDomainOwner(Domain $domain, $period=null, $extra=array())
		{
			//throw new NotImplementedException();
			// TODO: Testing and fixing
                        $params = array( 'order' => array(
                                 'Type' => 'Owner_Change',
                                 'Comments' => 'EPP-DRS ChangeDomainOwner Order',
                                 'Domain' => array(
                                 'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
                                 'Registrant' =>  array('Handle' => $domain->GetContact(CONTACT_TYPE::REGISTRANT)->CLID),
                                 'AdminContact' => array('Handle' => $domain->GetContact(CONTACT_TYPE::ADMIN)->CLID),
                                 'TechContact' => array('Handle' => $domain->GetContact(CONTACT_TYPE::TECH)->CLID),
                                 'BillingContact' => array('Handle' => $this->Config->GetFieldByName('BillingContactCLID')->Value
                         ))));
                        // NS
                        $nameservers = $domain->GetNameserverList();
                        $n = 1;
                        foreach ($nameservers as $ns)
                        {
                                $params['order']['Domain']['NameServers']['NameServer' . $n]['HostName'] = $ns->HostName;
                                $n++;
                        }

                        $Resp = $this->Request('CreateOrder', $params);

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new ChangeDomainOwnerResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
			$Ret->OperationId = $Resp->Data->order->OrderId;

			return $Ret;
		}

	    
	    /**
	     * Lock Domain
	     *
	     * @param Domain $domain
	     * @param array $extra Some registry specific fields 
	     * @return LockDomainResponse
	     */
	    public function LockDomain(Domain $domain, $extra = array())
		{
			list($status, $errmsg, $code) = $this->SetDomainLock($domain, true);
			return new LockDomainResponse($status, $errmsg, $code);
		}
	    
	    /**
	     * Unlock Domain
	     *
	     * @param Domain $domain
	     * @param array $extra Some extra data
	     * @return UnLockDomainResponse
	     */
	    public function UnlockDomain(Domain $domain, $extra = array())
		{
			list($status, $errmsg, $code) = $this->SetDomainLock($domain, false);
			return new UnLockDomainResponse($status, $errmsg, $code);
		}
		
		private function SetDomainLock (Domain $domain, $lock)
		{
			$params = array( 'order' => array(
                                'Type' => 'Change_Locks',
                                'Comments' => 'EPP-DRS Domain Lock',
                                'Domain' => array(
				'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
				'TransferLock' => (int)(!$lock)
			)));
			
			$Resp = $this->Request('CreateOrder', $params);
			
			// OK when operation executed or lock already exists
			$status = $Resp->Succeed || $Resp->Code == 540 ? 
				REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
			return array($status, $Resp->ErrMsg, $Resp->Code);

		}
	    
	    /**
	     * Update domain flags.
	     * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollUpdateDomain().
	     *
	     * @param Domain $domain
	     * @param IChangelist $changes A list of changes in domain flags for the domain
	     * @return UpdateDomainFlagsResponse
	     */
	    public function UpdateDomainFlags(Domain $domain, IChangelist $changes)
		{
			throw new NotImplementedException();
		}
	    
	    /**
		 * Register domain.
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollCreateDomain().
		 *	 
		 * @param Domain $domain
		 * @param int $period Domain registration period
		 * @param array $extra Extra fields
		 * @return CreateDomainResponse
		 */
		public function CreateDomain(Domain $domain, $period, $extra = array())
		{
			$contact_list = $domain->GetContactList();

			$params = array( 'order' => array(
				'Type' => 'Register_Domain',
				'Comments' => 'EPP-DRS Order',
				'Domain' => array(
				'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
				'RegPeriod' => $period,
				'Registrant' =>  array('Handle' => $contact_list[CONTACT_TYPE::REGISTRANT]->CLID),
				'AdminContact' => array('Handle' => $contact_list[CONTACT_TYPE::ADMIN]->CLID),
				'TechContact' => array('Handle' => $contact_list[CONTACT_TYPE::TECH]->CLID),
				'BillingContact' => array('Handle' => $contact_list[CONTACT_TYPE::BILLING]->CLID),
				)
			));
	
			// IDN tag
			$is_idna = $this->RegistryAccessible->IsIDNHostName($domain->Name);
			if ($is_idna)
			{
				$params['EncodingType'] = $domain->IDNLanguage;
			}

			// NS
			$nameservers = $domain->GetNameserverList();			
			$n = 1;
			foreach ($nameservers as $ns)
			{
				$params['order']['Domain']['NameServers']['NameServer' . $n]['HostName'] = $ns->HostName;
				$n++;
			}
			// TLD specific extra fields
			$params = array_merge($params, $extra);
			
			// Request Ascio 
			$Resp = $this->Request('CreateOrder', $params);

                        if ($Resp->Succeed)
                            $status = REGISTRY_RESPONSE_STATUS::PENDING;
                        elseif (!$Resp->Succeed && $Resp->CreateOrderResult->ResultCode !== 200)
                            $status = REGISTRY_RESPONSE_STATUS::FAILED;

			$Ret = new CreateDomainResponse($status, $Resp->ErrMsg, $Resp->Code);

                        if ($Resp->Succeed)
                        {
                                $domain->SetExtraField('OrderID', $Resp->Data->order->OrderId);
				$Ret->OperationId = $Resp->Data->order->OrderId;
                                $Ret->CreateDate = time();
                                $Ret->ExpireDate = time()+86400*365*$period;

	                }
			return $Ret;
		}
	
                /**
                 * Get OrderID from pending_operations queue
                 *
                 */
                private function GetPendingID ($object_type=null, $object_id=null, $op_type=null)
                {
			$ops = $this->RegistryAccessible->GetPendingOperationList($object_type, $object_id);
			foreach ($ops as $op) {
                                if ($op->Type == $op_type) {
                                         $PendingID = $op->RegistryOpId;
                                break;
                                }
			}
                        return $PendingID;
                }

                /**
                 * Get DomainHandle by Domain
                 *
                 */
                private $DomainHandle;

                private function GetDomainHandle ($domain)
                {
                        if ($this->DomainHandle === null)
                        {

				$params= array( 'criteria' => array(
				'Mode' => 'Strict',
				'Clauses' => Array(
				'Clause' => Array(
                                'Attribute' => 'DomainName',
                                'Value' => $domain, 
				'Operator' => 'Is'))));

				$Resp = $this->Request('SearchDomain', $params);
                        	$DomainHandle = (string)$Resp->Data->domains->Domain->{'DomainHandle'};

                        }
                        return $DomainHandle;
                }
	
		/**
		 * Obtain information about specific domain from registry   
		 * 
		 * @param Domain $domain 
		 * @return GetRemoteDomainResponse
		 */
		public function GetRemoteDomain(Domain $domain)
		{

			$DomHandle = $this->GetDomainHandle($this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension));

			$params = array(
				'domainHandle' => $DomHandle
			);
			
			$Resp = $this->Request('GetDomain', $params);
			if (!$Resp->Succeed)
			{
				$Ret = new GetRemoteDomainResponse(REGISTRY_RESPONSE_STATUS::FAILED, $Resp->ErrMsg, $Resp->Code);
				return $Ret;
			}
			$Ret = new GetRemoteDomainResponse(REGISTRY_RESPONSE_STATUS::SUCCESS, $Resp->ErrMsg, $Resp->Code);
			$Ret->CLID = $this->GetRegistrarID();
			$Ret->CRID = $Ret->CLID;
                        $Ret->ExpireDate = strtotime($Resp->Data->domain->{'ExpDate'});
                        $Ret->RegistryStatus = (string)$Resp->Data->domain->{'Status'};
			$Ret->AuthCode = (string)$Resp->Data->domain->{'AuthInfo'};
			$Ret->CreateDate = $cr_date ? $cr_date : strtotime("-1 year", $Ret->ExpireDate); 
			
			// Get DNS
			$n = 1;
                        foreach ($Resp->Data->domain->NameServers as $nameserver)
                        {
                                if ($nameserver->HostName)
                                {
                                        $list["NameServer{$n}"] = new Nameserver($nameserver->HostName);
                                        $n++;
                                }
                        }
			$Ret->SetNameserverList($list);

			// TODO: process ns hosts
			
			// Get Lock
			$Ret->IsLocked = (string)$Resp->Data->domain->{'TransferLock'} == '1';
			
			// Get Contact Handles
			$Ret->RegistrantContact = (string)$Resp->Data->domain->Registrant->Handle;
					
			if (($AuxBilling = $Resp->Data->domain->BillingContact->Handle))
			{
				$Ret->BillingContact = (string)$AuxBilling;
			}
			if (($Tech = $Resp->Data->domain->TechContact->Handle))
			{
				$Ret->TechContact = (string)$Tech;
			}
			if (($Admin = $Resp->Data->domain->AdminContact->Handle))
			{
				$Ret->AdminContact = (string)$Admin;
			}
			return $Ret;
		}
		
		/**
		 * Performs epp host:info command. Returns host IP address
		 *
		 * @return string
		 */
		/*
		public function GetHostIpAddress ($hostname)
		{
			$params = array(
				'CheckNSName' => $hostname
			);
			$response = $this->Request('CheckNSStatus', $params);
			if (!$response->Succeed)
				throw new Exception($response->ErrMsg);
				
			$result = $response->Data->response->resData->children(self::NAMESPACE);
			return (string)$result[0]->addr;
		}
		*/
		
		/**
		 * Swap domain's existing contact with another one
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollCreateDomain().
		 * 
		 * @param Domain $domain Domain
		 * @param string $contactType contact type. Should be one of CONTACT_TYPE members.
		 * @param Contact $oldContact Old contact or NULL
		 * @param Contact $newContact
		 * @return UpdateDomainContactResponse
		 */
		public function UpdateDomainContact(Domain $domain, $contactType, Contact $oldContact, Contact $newContact)
		{

			$contact_list = $domain->GetContactList();
			$params = array( 'order' => array(
                                'Comments' => 'EPP-DRS Contact Update Order',
                                'Domain' => array(
                                'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
                                'Registrant' =>  array('Handle' => $contact_list[CONTACT_TYPE::REGISTRANT]->CLID),
                                'AdminContact' => array('Handle' => $contact_list[CONTACT_TYPE::ADMIN]->CLID),
                                'TechContact' => array('Handle' => $contact_list[CONTACT_TYPE::TECH]->CLID),
                                'BillingContact' => array('Handle' => $contact_list[CONTACT_TYPE::BILLING]->CLID),
				)));

               		switch($contactType)
                	{
                        	case CONTACT_TYPE::REGISTRANT:
                                	$params['order']['Domain']['Registrant']['Handle'] = $newContact->CLID;
                                	if ($oldContact)
                                        	$params['order']['Domain']['Registrant']['Handle'] = $oldContact->CLID;
                                	break;

                        	case CONTACT_TYPE::ADMIN:
                                	$params['order']['Domain']['AdminContact']['Handle'] = $newContact->CLID;
                                	if ($oldContact)
                                        	$params['order']['Domain']['AdminContact']['Handle'] = $oldContact->CLID;
                                	break;

                        	case CONTACT_TYPE::BILLING:
                                	$params['order']['Domain']['BillingContact']['Handle'] = $newContact->CLID;
                                	if ($oldContact)
                                        	$params['order']['Domain']['BillingContact']['Handle'] = $oldContact->CLID;
                                	break;

                        	case CONTACT_TYPE::TECH:
                                	$params['order']['Domain']['TechContact']['Handle'] = $newContact->CLID;
                                	if ($oldContact)
                                        	$params['order']['Domain']['TechContact']['Handle'] = $oldContact->CLID;
                                	break;
                	}

			if ($contactType == CONTACT_TYPE::REGISTRANT)
			{
					unset($params['order']['Domain']['AdminContact']);
					unset($params['order']['Domain']['BillingContact']);
					unset($params['order']['Domain']['TechContact']);
				        $params['order']['Type'] = 'Owner_Change';
			} else {
					unset($params['order']['Domain']['Registrant']);
					$params['order']['Type'] = 'Contact_Update';
			}

			$Resp = $this->Request('CreateOrder', $params);

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new UpdateDomainContactResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;

			return $Ret;		
		}
		
		/**
		 * Change nameservers for specific domain 
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollUpdateDomain().
		 * 
		 * @param Domain $domain Domain
		 * @param IChangelist $changelist Changes in a list of nameservers 
		 * @return UpdateDomainNameserversResponse
		 */
		public function UpdateDomainNameservers(Domain $domain, IChangelist $changelist)
		{
			$nameservers = $changelist->GetList();
			if (!$nameservers)
			{
				throw new Exception(_("Ascio can't assign empty list of nameservers to domain"));
			}
			
                        $params = array( 'order' => array(
                                'Type' => 'Nameserver_Update',
                                'Comments' => 'EPP-DRS Nameserver Update Order',
                                'Domain' => array(
                                'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension)
                        )));
			
			$n = 1;
			foreach ($nameservers as $nameserver)
			{
				if ($nameserver->HostName)
				{
					$params["NameServer{$n}"] = $nameserver->HostName;
					$n++;
				}
			}
			
			$Resp = $this->Request('CreateOrder', $params);

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new UpdateDomainNameserversResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;

                        return $Ret;	
		}	
		
		/**
		 * Called to check either domain can be registered
		 * 
		 * @param Domain $domain Domain
		 * @return DomainCanBeRegisteredResponse
		 */
		public function DomainCanBeRegistered(Domain $domain)
		{
			$params = array(
				'domains' => array($this->MakeNameIDNCompatible($domain->Name)),
				'tlds' => array($this->Extension),
				'quality' => 'Smart',
			);
			
			$Resp = $this->Request('AvailabilityCheck', $params);
	                $Ret = new DomainCanBeRegisteredResponse(REGISTRY_RESPONSE_STATUS::SUCCESS, $Resp->ErrMsg, $Resp->Code);
        	        $Ret->Result = ($Resp->Data->results->AvailabilityCheckResult->StatusCode == 200);
			$Ret->Reason = "{$Resp->ErrMsg}";
                        	return $Ret;
		}

	
		
		/**
		 * Completely delete domain from registry if it is delegated or  
		 * recall domain name application if it was not yet delegated.
		 * @param Domain $domain Domain
		 * @param int $executeDate Unix timestamp for scheduled delete. Null for immediate delete.
		 * @return DeleteDomainResponse
		 * @throws ProhibitedTransformException 
		 */
		public function DeleteDomain(Domain $domain, $executeDate=null)
		{


                        $params = array( 'order' => array(
                                'Type' => 'Delete_Domain',
                                'Comments' => 'EPP-DRS Delete Order',
                                'Domain' => array(
                                'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension)
			)));
			
			$Resp = $this->Request('CreateOrder', $params);

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new DeleteDomainResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;

                        return $Ret;
			
		}
		
		/**
		 * Send renew domain request
		 *
		 * @param string $domain Domain
		 * @param array $extradata Extra fields
		 * @return RenewDomainResponse
		 */
		public function RenewDomain(Domain $domain, $extra=array())
		{
			$params = array( 'order' => array(
				'Type' => 'Renew_Domain',
				'Domain' => array(
				'Comments' => 'EPP-DRS Renew Order',
				'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
				'RegPeriod' => $extra['period']
			)));
			
			$Resp = $this->Request('CreateOrder', $params);
			
			$status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
			$Ret = new RenewDomainResponse($status, $Resp->ErrMsg, $Resp->Code);
			if ($Ret->Succeed())
			{
				$Ret->ExpireDate = strtotime("+{$extra['period']} year", $domain->ExpireDate);
			}
			return $Ret;
		}
	
		/**
		 * Send a request for domain transfer
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollTransfer().
		 * 
		 * @param string $domain Domain
		 * @param array $extradata Extra fields
		 * @return TransferRequestResponse
		 */	
		public function TransferRequest(Domain $domain, $extra=array())
		{
			$params = array( 'order' => array(
				'Type' => 'Transfer_Domain',
				'Domain' => array(
				'Comments' => 'EPP-DRS Transfer Order',
				'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
				'AuthInfo' => $extra['pw']
			)));
			$Resp = $this->Request('CreateOrder', $params);

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new TransferRequestResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
			$Ret->TransferID = (string)$Resp->Data->order->OrderId;

                        return $Ret;
		}
		
		/**
		 * Approve domain transfer
		 * In order to pending operation, response must have status REGISTRY_RESPONSE_STATUS::PENDING
		 *
		 * @param string $domain Domain
		 * @param array $extradata Extra fields
		 * @return TransferApproveResponse
		 */
		public function TransferApprove(Domain $domain, $extra=array())
		{
			throw new NotImplementedException();
		}
		
		/**
		 * Reject domain transfer
		 *
		 * @param string $domain Domain
		 * @param array $extradata Extra fields
		 * @return TransferRejectResponse
		 */
		public function TransferReject(Domain $domain, $extra=array())
		{
			throw new NotImplementedException();
		}		
		
		/**
		 * Called to check either this nameserver is a valid nameserver.
		 * This method request registry for ability to create namserver
		 * 
		 * @param Nameserver $ns
		 * @return NameserverCanBeCreatedResponse
		 */
		public function NameserverCanBeCreated(Nameserver $ns)
		{

			/*
			$params = array(
			//	'CheckNSName' => $ns->HostName			
			);
			$Resp = $this->Request('CheckNSStatus', $params);

			$valid_codes = array(
				545, // RRP entity reference not found
				541	 // Parameter value range error;Host name does not exist.
			);
			
			$status = $Resp->Succeed || in_array($Resp->Code, $valid_codes) ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
			$Ret = new NameserverCanBeCreatedResponse($status, $Resp->ErrMsg, $Resp->Code);
			$Ret->Result = in_array($Resp->Code, $valid_codes);
			return $Ret;
			*/

			//disabled 12.12.2013 !!
			throw new NotImplementedException();
		}
		
		/**
		 * Create namserver
		 * 
		 * @param Nameserver $ns
		 * @return CreateNameserverResponse
		 */
		public function CreateNameserver (Nameserver $ns)
		{
			throw new NotImplementedException();
		}
		
		/**
		 * Create nameserver host (Nameserver derived from our own domain)
		 * 
		 * @param NameserverHost $nshost
		 * @return CreateNameserverHostResponse
		 */
		public function CreateNameserverHost (NameserverHost $nshost)
		{
			$params = array(
				'Add' => 'true',
				'HostName' => $nshost->HostName,
				'IpAddress' => $nshost->IPAddr
			);
			
			$Resp = $this->Request('CreateNameServer', $params);
			
	    	$status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
	    	$Ret = new CreateNameserverHostResponse($status, $Resp->ErrMsg, $Resp->Code);
	    	$Ret->Result = ($Resp->Data->ErrCount == 0);
			return $Ret;
		}
		
		/**
		 * Update nameserver host
		 * 
		 * @param NameserverHost $ns
		 * @return UpdateNameserverHostResponse 
		 */
		public function UpdateNameserverHost(NameserverHost $ns)
		{
			$params = array(
				'CheckNSName' => $ns->HostName			
			);
			$Resp = $this->Request('CheckNSStatus', $params);
			if (!$Resp->Succeed)
			{
				return new UpdateNameserverHostResponse(
					REGISTRY_RESPONSE_STATUS::FAILED, 
					$Resp->ErrMsg, 
					$Resp->Code
				);
			}
			
			$old_ip_addr = (string)$Resp->Data->CheckNsStatus->ipaddress;
			$params = array(
				'NS' => $ns->HostName,
				'NewIP' => $ns->IPAddr,
				'OldIP' => $old_ip_addr
			);
			$Resp = $this->Request('UpdateNameServer', $params);
			
			$Ret = new UpdateNameserverHostResponse(
				$Resp->Succeed ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED,
				$Resp->ErrMsg,
				$Resp->Code
			);
			$Ret->Result = $Resp->Succeed && (int)$Resp->Data->RegisterNameserver->NsSuccess;
			return $Ret;
		}
		
		/**
		 * Delete namserver host from registry
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollDeleteNameserverHost().
		 * 
		 * @param NameserverHost $ns
		 * @return DeleteNameserverHostResponse 
		 * @throws ProhibitedTransformException 
		 */
		public function DeleteNameserverHost(NameserverHost $ns)
		{
			$params = array(
				'NS' => $ns->HostName
			);
			
			$Resp = $this->Request('DeleteNameServer', $params);
		
                        if ($Resp->Succeed)
                            $status = REGISTRY_RESPONSE_STATUS::PENDING;
                        elseif (!$Resp->Succeed)
                            $status = REGISTRY_RESPONSE_STATUS::FAILED;

                        $Ret = new DeleteNameserverHostResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;
                        return $Ret;
		}

		/**
		 * Called to check either specific contact can be created 
		 * 
		 * @param Contact $contact
		 * @return ContactCanBeCreatedResponse 
		 */
		public function ContactCanBeCreated(Contact $contact)
		{
			$Ret = new ContactCanBeCreatedResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
			$Ret->Result = true;
			return $Ret;
		}
	
		/**
		 * Create contact
		 * 
		 * @param Contact $contact
		 * @return CreateContactResponse
		 */
		public function CreateContact(Contact $contact, $extra=array())
		{

			$contact_type = $contact->GroupName;
			$packcontact_type = CONTACT_TYPE::ADMIN;

			if ($contact_type == "registrant"){
			$packcontact_type = CONTACT_TYPE::REGISTRANT;
			}

				$params = $this->PackContact($contact, $packcontact_type);
				$Resp = $this->Request('Create'.ucfirst($contact_type), $params);
				$status = $Resp->Succeed || $Resp->Data->$contact_type->Handle ? 
				REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
				$Ret = new CreateContactResponse($status, $Resp->ErrMsg, $Resp->Code);

			if ($Ret->Succeed())
			{
				$Ret->CLID = $Resp->Data->$contact_type->Handle;
			}
			return $Ret;
		}

		/**
		 * Must return detailed information about contact from registry
		 * @access public
		 * @param Contact $contact
		 * @version GetRemoteContactResponse
		 */
		public function GetRemoteContact(Contact $contact)
		{
                        $contact_type = $contact->GroupName;
                        $params[$contact_type.'Handle'] = $contact->CLID;

                        $Resp = $this->Request('Get'.ucfirst($contact_type), $params);
                        $status = $Resp->Succeed ?
			REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new GetRemoteContactResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $contact_data = (array)$Resp->Data->$contact_type;
                        foreach ($contact_data as $k=>$contactvar)
                        {
                                 $Ret->$k =(string)$contactvar;
			}
                        return $Ret;
		}
			
		/**
		 * Update contact fields
		 * 
		 * @param Contact $contact
		 * @return UpdateContactResponse
		 */
		public function UpdateContact(Contact $contact)
		{
			
			//$params = $this->PackContact($contact, $packcontact_type);
			//$params['contact']['Type'] = $this->contact_type_prefix_map[$contact->ExtraData['type']];

			$contact_type = $contact->GroupName;
                        $packcontact_type = CONTACT_TYPE::ADMIN;

                        if ($contact_type == "registrant"){
                        $packcontact_type = CONTACT_TYPE::REGISTRANT;
                        }

			$params = $this->PackContact($contact, $packcontact_type);

			$params[$contact_type]['Handle'] = $contact->CLID;

			// If contact Group == registrant, remove Name/Org from array and use Registrant_Details_Update instead of UpdateContact
			if ($contact_type == "registrant"){
                        unset($params['registrant']['Name']);
                        unset($params['registrant']['OrgName']);
                        unset($params['registrant']['OrganisationNumber']);
                        unset($params['registrant']['Handle']);
                        $paramsRegistrant = array('order' => array(
                        'Type' => 'Registrant_Details_Update',
                        'Comments' => 'EPP-DRS Registrant Details Update',
                        'Domain' => array(
                        'DomainName' => $this->MakeNameIDNCompatible($contact->ExtraData['domainname'].'.'.$this->Extension),
                        'Registrant' => $params['registrant']
                        )));
			$Resp = $this->Request('CreateOrder', $paramsRegistrant);
			} else {
			$Resp = $this->Request('UpdateContact', $params);
			}

                        $status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new UpdateContactResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;

			return $Ret;		
		}
	
		/**
		 * Delete contact
		 * 
		 * This operation supports pending status. If you return response object with Status = REGISTRY_RESPONSE_STATUS.PENDING, you must return response later during a poll.
	     * See IRegistryModuleClientPollable::PollDeleteContact().
		 *
		 * @param Contact $contact
		 * @param array $extra Extra fields
		 * @return DeleteContactResponse
		 * @throws ProhibitedTransformException
		 */
		public function DeleteContact(Contact $contact, $extra = array())
		{

                        $contact_type = $contact->GroupName;
			$params = array($contact_type.'Handle' => $contact->CLID);

			$Resp = $this->Request('Delete'.ucfirst($contact_type), $params);

			$status = $Resp->Succeed ? REGISTRY_RESPONSE_STATUS::PENDING : REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret = new DeleteContactResponse($status, $Resp->ErrMsg, $Resp->Code);
                        $Ret->Result = $status != REGISTRY_RESPONSE_STATUS::FAILED;
                        $Ret->OperationId = $Resp->Data->order->OrderId;

                        return $Ret;

		}
		
		
		/**
		 * Called by the system if CreateDomainResponse->Status was REGISTRY_RESPONSE_STATUS::PENDING in IRegistryModule::CreateDomainResponse() 
		 * EPP-DRS calls this method to check the status of domain registration operation.
		 *  
		 * Must return one of the following:
		 * 1. An object of type DomainCreatedResponse if operation is completed 
		 * 2. PollCreateDomainResponse with Status set to REGISTRY_RESPONSE_STATUS::PENDING, if domain creation is still in progress
		 * 
		 * @param Domain $domain
		 * @return PollCreateDomainResponse
		 */
        	public function PollCreateDomain (Domain $domain)
        	{
                	try
                	{
				$params = array('orderId' => $this->GetPendingID(Registry::OBJ_DOMAIN, $domain->ID, Registry::OP_CREATE));
                        	$Resp = $this->Request('GetOrder', $params);
				$rs = (string)$Resp->Data->order->Status;

                        	if ($Resp->Succeed && $rs != 'Invalid' && $rs != 'Failed')
                        	{

                                	if ($rs != 'Completed')
                                	$rs = (string)'PENDING';

                                	$status = $rs == 'Completed' || $rs != 'PENDING' ?
                                        	REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::PENDING;
                                	$Ret = new PollCreateDomainResponse($status);
                                	$Ret->HostName = $domain->GetHostName();

                                	if ($rs == 'Completed')
                                	{
                                        	$Ret->Result = true;
                                	}
                                	else if ($rs != 'PENDING')
                                	{
                                        	$Ret->Result = false;
                                	}
                                	return $Ret;
                        	}
                        	else
                        	{
                                	return new PollCreateDomainResponse(
                                        	REGISTRY_RESPONSE_STATUS::FAILED,
                                        	$Resp->ErrMsg,
                                        	$Resp->Code
                                	);
                        	}
                	}
                	catch (ObjectNotExistsException $e)
                	{
                        	$Ret = new PollCreateDomainResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                        	$Ret->HostName = $domain->GetHostName();
                        	$Ret->Result = false;
                        	$Ret->FailReason = _("Domain registration declined by registry");
                        	return $Ret;
                	}
        	}
		/**
		 * EPP-DRS calls this method to check the status of DeleteDomain() operation.
		 *  
		 * Must return one of the following:
		 * 1. An object of type DeleteDomainResponse if operation is completed. 
		 * 2. DeleteDomainResponse with Status set to REGISTRY_RESPONSE_STATUS::PENDING, if domain creation is still in progress
		 *  
		 * @param Domain $domain
		 * @return PollDeleteDomainResponse
		 */
		public function PollDeleteDomain (Domain $domain)
		{
                        try
                        {
                                $params = array('orderId' => $this->GetPendingID(Registry::OBJ_DOMAIN, $domain->ID, Registry::OP_DELETE));
                                $Resp = $this->Request('GetOrder', $params);
                                $rs = (string)$Resp->Data->order->Status;

                                if ($Resp->Succeed && $rs != 'Invalid' && $rs != 'Failed')
                                {

                                        if ($rs != 'Completed')
                                        $rs = (string)'PENDING';

                                        $status = $rs == 'Completed' || $rs != 'PENDING' ?
                                                REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::PENDING;
                                        $Ret = new PollDeleteDomainResponse($status);
                                        $Ret->HostName = $domain->GetHostName();

                                        if ($rs == 'Completed')
                                        {
                                                $Ret->Result = true;
                                        }
                                        else if ($rs != 'PENDING')
                                        {
                                                $Ret->Result = false;
                                        }
                                        return $Ret;
                                }
                                else
                                {
                                        return new PollDeleteDomainResponse(
                                                REGISTRY_RESPONSE_STATUS::FAILED,
                                                $Resp->ErrMsg,
                                                $Resp->Code
                                        );
                                }
                        }
                        catch (ObjectNotExistsException $e)
                        {
                                $Ret = new PollDeleteDomainResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                                $Ret->HostName = $domain->GetHostName();
                                $Ret->Result = false;
                                $Ret->FailReason = _("Domain deletion declined by registry");
                                return $Ret;
                        }
	
		}
		
		/**
		 * Called by system when change domain owner operation is pending.
		 * Must return valid DomainOwnerChangedResponse if operatation is completed, 
		 * or response with Status = REGISTRY_RESPONSE_STATUS::PENDING if operation is still in progress
		 * 
		 * @param Domain $domain
		 * @return PollChangeDomainOwnerResponse
		 */
		public function PollChangeDomainOwner (Domain $domain)
		{
	
		// TODO: Fixing and testing	
                $AWSResp = $this->GetRemoteDomain($domain);
		$Registrant_remote_clid = $AWSResp->CLID;
		$Registrant_new_clid = GetContact(CONTACT_TYPE::REGISTRANT)->CLID;

                if ($AWSResp->Succeed())
                {
                        if ($Registrant_remote_clid == $Registrant_new_clid)
                        {
                                $resp = new PollChangeDomainOwnerResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                                $resp->HostName = $domain->GetHostName();
                                $resp->Result = true;
                                return $resp;
                        }
                        else if ($Registrant_remote_clid !== $Registrant_new_clid)
                        {
                                $resp = new PollChangeDomainOwnerResponse(REGISTRY_RESPONSE_STATUS::PENDING);
                                $resp->HostName = $domain->GetHostName();
                                return $resp;
                        }
                        else
                        {
                                $resp = new PollChangeDomainOwnerResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                                $resp->HostName = $domain->GetHostName();
                                $resp->Result = false;
                                return $resp;
                        }
                }
              	else
                        return new PollChangeDomainOwnerResponse(REGISTRY_RESPONSE_STATUS::FAILED, $AWSResp->ErrMsg, $AWSResp->Code);
        	}

	
		/**
		 * Transfer status constants
		 * 
		 * @see http://aws.ascio.info/docs/AWSReference-2.0.8.pdf 8.3 GetOrder
		 */
		// TODO: Durch korrekte Params ersetzen
		const TRANSFERSTATUS_CANCELLED = 2;
		const TRANSFERSTATUS_COMPLETE = 3;
		
		/**
		 * Called by system when domain transfer operation is pending.
		 * Must return valid PollDomainTransfered on operatation is completed, 
		 * or response with Status = REGISTRY_RESPONSE_STATUS::PENDING if operation is still in progress
		 * 
		 * @param Domain $domain
		 * @return PollTransferResponse
		 */
		public function PollTransfer (Domain $domain)
		{
			$params = array(
				//'DomainName' => $this->MakeNameIDNCompatible($domain->Name.'.'.$domain->Extension),
				'orderId' => $domain->TransferID);

			$Resp = $this->Request('GetOrder', $params);
			
			if ($Resp->Succeed)
			{
				// TODO: Durch richtiges Objekt ersetzen
				$statusid = (int)$Resp->Data->transferorder->statusid;
				if ($statusid == self::TRANSFERSTATUS_COMPLETE)
				{
					$tstatus = TRANSFER_STATUS::APPROVED;
				}
				else if ($statusid == self::TRANSFERSTATUS_CANCELLED)
				{
					$tstatus = TRANSFER_STATUS::DECLINED;
				}
				else
				{
					$tstatus = TRANSFER_STATUS::PENDING;
				}
				
				$Ret = new PollTransferResponse(
					$tstatus != TRANSFER_STATUS::PENDING ? REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::PENDING, 
					$Resp->ErrMsg, 
					$Resp->Code
				);
				$Ret->HostName = $domain->GetHostName();
				$Ret->TransferStatus = $tstatus;
				return $Ret;
			}
			else
			{
				return new PollTransferResponse(
					REGISTRY_RESPONSE_STATUS::FAILED, 
					$Resp->ErrMsg,
					$Resp->Code
				);
			}
		}
		
		/**
		 * Called by system when update domain operation is pending.
		 * Must return valid DomainUpdatedResponse on operatation is completed, 
		 * or response with Status = REGISTRY_RESPONSE_STATUS::PENDING if update is still in progress
		 * 
		 * @param Domain $domain
		 * @return PollUpdateDomainResponse
		 */
		public function PollUpdateDomain (Domain $domain)
		{
			try
                        {
                        	$params = array('orderId' => $this->GetPendingID(Registry::OBJ_DOMAIN, $domain->ID, Registry::OP_UPDATE));
                                $Resp = $this->Request('GetOrder', $params);
                                $rs = (string)$Resp->Data->order->Status;

                                if ($Resp->Succeed && $rs != 'Invalid' && $rs != 'Failed')
                                {
					if ($rs != 'Completed')
                                        $rs = (string)'PENDING';

                                        $status = $rs == 'Completed' || $rs != 'PENDING' ?
                                                REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::PENDING;
                                        $Ret = new PollUpdateDomainResponse($status);
                                        $Ret->HostName = $domain->GetHostName();

                                        if ($rs == 'Completed')
                                        {
                                                $Ret->Result = true;
                                        }
                                        else if ($rs != 'PENDING')
                                        {
                                                $Ret->Result = false;
                                        }
                                        return $Ret;
                                }
                                else
                                {
                                        return new PollUpdateDomainResponse(
                                                REGISTRY_RESPONSE_STATUS::FAILED,
                                                $Resp->ErrMsg,
                                                $Resp->Code
                                        );
                                }
                        }
                        catch (ObjectNotExistsException $e)
                        {
                                $Ret = new PollUpdateDomainResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                                $Ret->HostName = $domain->GetHostName();
                                $Ret->Result = false;
                                $Ret->FailReason = _("Domain Update declined by registry");
                                return $Ret;
                        }	
		}

		/**
		 * Called by system when delete contact operation is pending
		 *
		 * @param Contact $contact
		 * @return PollDeleteContactResponse
		 */
		public function PollDeleteContact (Contact $contact)
		{

                        try
                        {
                                $params = array('orderId' => $this->GetPendingID(Registry::OBJ_CONTACT, $contact->ID, Registry::OP_DELETE));
                                $Resp = $this->Request('GetOrder', $params);
                                $rs = (string)$Resp->Data->order->Status;

                                if ($Resp->Succeed && $rs != 'Invalid' && $rs != 'Failed')
                                {

                                        if ($rs != 'Completed')
                                        $rs = (string)'PENDING';

                                        $status = $rs == 'Completed' || $rs != 'PENDING' ?
                                                REGISTRY_RESPONSE_STATUS::SUCCESS : REGISTRY_RESPONSE_STATUS::PENDING;
                                        $Ret = new PollDeleteContactResponse($status);

                                        if ($rs == 'Completed')
                                        {
                                                $Ret->Result = true;
                                        }
                                        else if ($rs != 'PENDING')
                                        {
                                                $Ret->Result = false;
                                        }
                                        return $Ret;
                                }
                                else
                                {
                                        return new PollDeleteContactResponse(
                                                REGISTRY_RESPONSE_STATUS::FAILED,
                                                $Resp->ErrMsg,
                                                $Resp->Code
                                        );
                                }
                        }
                        catch (ObjectNotExistsException $e)
                        {
                                $Ret = new PollDeleteContactResponse(REGISTRY_RESPONSE_STATUS::SUCCESS);
                                $Ret->Result = false;
                                $Ret->FailReason = _("Contact deletion declined by registry");
                                return $Ret;
                        }	
		}
		
		/**
		 * Called by system when delete nameserver host operation is pending
		 *
		 * @param NamserverHost $nshost
		 * @return PollDeleteNamserverHostResponse
		 */
		public function PollDeleteNamserverHost (NamserverHost $nshost)
		{
			
		}		
	}
?>
