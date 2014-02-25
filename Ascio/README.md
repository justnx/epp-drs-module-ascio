Ascio EPP-DRS Module
=======

[API Reference Documentation](http://aws.ascio.info/docs/AWSReference-2.0.8.pdf)

NOTES

* Very important is to set the date.timezone in php.ini, else ('Too quick to poll for #%s operation) will apear in Log all the time and request to remote registry') polling will never or start to late! Reason: The 60 second check logic cannot check against time() call which is empty in that case.

ADDED

* registrant type template Full Name modules.xml integrated
* locked Name/Org for registrant contacts in modules.xml
* Delete User (delete registrant User)
* GetRemoteContact
* polling tasks for different order types
* write pendingid to polling queue and check status of polling tasks

FIXED

* direct contact updates via menu unequal registrant contact
* code updates regarding contact type: Registrant (Any)
* Disallow lock flagging for .de TLDs. are there any more?
* Change User Data
* write orderid to db table: domains data
* fix for update Status Delegated -> Trasfered bug

TODO

* testing TransferDomain/TransferPollDomain
* final Change Domain Owner testing and fixing
* testing Transfer Domain
