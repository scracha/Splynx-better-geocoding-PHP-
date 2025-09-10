# Splynx-better-geocoding-PHP-
Uses the API to iterate through all customers' ACTIVE internet services in splynx and export to a CSV file as well as implementing better geocoding via google API in the event OpenStreetmap doesn't return anything valid.
This handles scenarios where customers have multiple internet services.

Will only update a customer's internet service geo data where
a) additional attribue installstreet or installtown differ from geo->address
or
b) there is no existing geo data->marker (latitude, longitude) nor populated additional attribute installstreet or installtown.  In this instance, the geo data will assume the customer address.

CSV export to splynx_customers_geo_data.csv
format is
customer_id, customer_name, customer_login, customer_email, customer_status, internet_service_name, internet_service_status, internet_service_ipv4, internet_service_router_name, service_street, service_town, service_latitude, service_longitude


Please enable basic authentication on the splynx API key.  
Tarrif Plans->internet->view
Customers->customer->view
Customer information->view
customer->internet services->view and Update
networking->routers->view

Recommend creating a demo company with a sample of customers and limiting the API key to this prior to applying this to your live splynx database.  STRONGLY recommend backing up your splynx data prior to running.


Populate the config.php with your Splynx URL, api Key, api Secret and google API Key (for geocoding)

to execute
php splynx_customers_geoupdate_to_csv.php
