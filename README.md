CiviCRM CardConnect Payment Processor
--------------------------------

CONFIGURATION
-------------
All configuration is in the standard Payment Processors settings area in CiviCRM admin.  
The Site URL and Recurring Payments URL does not include the port number.
Use Payment Mode, Void on AVS and Void on CVV failure based on your preferences.

RECURRING PAYMENTS
---------
The recurring payment will be hooked with CiviCRM Cron. Please make sure you make the CardConnect Recurring Payments in scheduled jobs active so CardConnect Payment Processor can process recurring payments everyday.

INSTALLATION
------------
Install extension via CiviCRM's "Manage Extensions" page.

AUTHOR INFO
-----------
Mike Nguyen
http://www.sofcorp.com/

OTHER CREDITS
-------------
For bug fixes, new features, and documentiation, thanks to:
Greg Phillips, Rex Keal and Terry Woodward
