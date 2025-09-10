<?php

/**
 * Splynx API Client Script
 *
 * This script retrieves a list of all active Splynx customers
 * and their associated internet services, including geo-location data.
 * It uses a separate configuration file for API credentials.
 * The script is modified to create a CSV file of the output data.
 */

// Include the configuration file
// IMPORTANT: Make sure to create a file named 'config.php' in the same directory.
// This file should contain:
// $splynxBaseUrl = 'https://your.splynx.url/api/2.0';
// $apiKey = 'your_splynx_api_key';
// $apiSecret = 'your_splynx_api_secret';
// $googleApiKey = 'your_google_geocoding_api_key'; // NEW: Add your Google API key here
require_once 'config.php';

// Define the name of the output CSV file
$csvFileName = 'splynx_customers_geo_data.csv';

/**
 * Splynx API Client Class
 *
 * This class provides a basic wrapper for interacting with the Splynx API.
 * It uses Basic Authentication for API requests.
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;
    private $googleApiKey;

    // Static properties for Nominatim rate-limiting
    private static $lastNominatimRequestTime = 0;
    private const NOMINATIM_REQUEST_INTERVAL = 1000000; // 1 second in microseconds

    // State for Google API key validity
    private $googleApiKeyIsValid = true;

    /**
     * Constructor
     *
     * @param string $apiUrl The base URL of your Splynx instance.
     * @param string $apiKey Your Splynx API Key.
     * @param string $apiSecret Your Splynx API Secret.
     * @param string|null $googleApiKey Your Google Geocoding API Key.
     */
    public function __construct($apiUrl, $apiKey, $apiSecret, $googleApiKey = null)
    {
        $this->apiUrl = rtrim($apiUrl, '/'); // Ensure no trailing slash
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->googleApiKey = $googleApiKey;
    }

    /**
     * Makes a GET request to the Splynx API using Basic Authentication.
     *
     * @param string $path The API endpoint path.
     * @param array $params Optional query parameters.
     * @return array|null The decoded JSON response, or null on error.
     */
    public function get($path, $params = [])
    {
        $queryString = http_build_query($params);
        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/') . ($queryString ? '?' . $queryString : '');

        // Construct the Basic Authentication header
        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else {
            // Echoing errors for CLI output
            echo "API GET request to {$fullUrl} failed with HTTP code {$httpCode}: " . ($response ?: 'No response body') . "\n";
            return null;
        }
    }

    /**
     * Makes a PUT request to the Splynx API to update a resource.
     *
     * @param string $path The API endpoint path.
     * @param array $data The data to send as a JSON body.
     * @return bool True on success, false on failure.
     */
    public function put($path, $data)
    {
        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/');
        
        // Construct the Basic Authentication header
        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); // Set the HTTP method to PUT
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // Set the JSON data
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check for 202 Accepted, as per Splynx API documentation
        if ($httpCode === 202) {
            return true;
        } else {
            // Echoing errors for CLI output
            echo "API PUT request to {$fullUrl} failed with HTTP code {$httpCode}: " . ($response ?: 'No response body') . "\n";
            return false;
        }
    }

    /**
     * Retrieves latitude and longitude from an address using the Nominatim API.
     *
     * Includes rate-limiting to adhere to Nominatim's usage policy.
     *
     * @param string $address The address to search for.
     * @return array|null An array with 'lat' and 'lon', or null on error.
     */
    public function getCoordinatesFromAddressOSM($address)
    {
        // Implement rate-limiting to a maximum of 1 request per second
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - self::$lastNominatimRequestTime;

        if ($elapsedTime < self::NOMINATIM_REQUEST_INTERVAL / 1000000) {
            $sleepTime = (self::NOMINATIM_REQUEST_INTERVAL / 1000000) - $elapsedTime;
            usleep($sleepTime * 1000000); // Sleep in microseconds
        }

        self::$lastNominatimRequestTime = microtime(true);

        $nominatimUrl = 'https://nominatim.openstreetmap.org/search';
        
        $params = [
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'nz' // Limit results to New Zealand
        ];
        
        $fullUrl = $nominatimUrl . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client/1.0 (contact@your-email.com)');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
                return ['lat' => $data[0]['lat'], 'lon' => $data[0]['lon']];
            }
        }
        
        return null;
    }

    /**
     * Retrieves latitude and longitude from an address using the Google Geocoding API.
     * This is a fallback method.
     *
     * @param string $address The address to search for.
     * @return array|null An array with 'lat' and 'lon', or null on error.
     */
    public function getCoordinatesFromAddressGoogle($address)
    {
        // Don't attempt if the key has been flagged as invalid
        if (!$this->googleApiKeyIsValid) {
            echo "Skipping Google Geocoding API call due to a previous invalid key.\n";
            return null;
        }
        
        if (empty($this->googleApiKey)) {
            echo "Google Geocoding API key is not configured.\n";
            $this->googleApiKeyIsValid = false;
            return null;
        }

        $googleUrl = 'https://maps.googleapis.com/maps/api/geocode/json';
        
        $params = [
            'address' => $address,
            'key' => $this->googleApiKey,
            'components' => 'country:nz' // Restrict search to New Zealand
        ];
        
        $fullUrl = $googleUrl . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        
        // Check for Google-specific errors
        if ($data['status'] !== 'OK') {
            echo "Google Geocoding API error: " . ($data['error_message'] ?? 'Unknown error') . ".\n";
            
            // Check for specific error messages that indicate an invalid key
            if ($data['status'] === 'REQUEST_DENIED') {
                echo "The Google API key appears to be invalid. Disabling further attempts.\n";
                $this->googleApiKeyIsValid = false;
            }
            return null;
        }
        
        if (!empty($data['results']) && isset($data['results'][0]['geometry']['location'])) {
            $location = $data['results'][0]['geometry']['location'];
            return ['lat' => $location['lat'], 'lng' => $location['lng']];
        }

        return null;
    }
}

echo "Initializing Splynx API client with Basic Authentication...\n";
$splynx = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret, $googleApiKey);

echo "\nRetrieving all active customers...\n";

// Define search parameters for active customers
$customerSearchParams = [
    'main_attributes' => [
        'status' => 'active'
    ]
];

// Make the API call to get customers with the 'active' status
$customers = $splynx->get(
    'admin/customers/customer', // Endpoint for listing customers
    $customerSearchParams
);

// Open the CSV file for writing
$csvFile = fopen($csvFileName, 'w');
if ($csvFile === false) {
    die("Error: Could not open the CSV file '{$csvFileName}' for writing.\n");
}

// Define the CSV header row
$csvHeader = [
    'Customer ID', 'Name', 'Login', 'Email', 'Status',
    'Internet Plan Name', 'Internet Plan Status',
    'IPv4', 'Router', 'Street', 'Town', 'Latitude', 'Longitude'
];
fputcsv($csvFile, $csvHeader);
echo "CSV file '{$csvFileName}' created with header row.\n";


if ($customers !== null) {
    if (!empty($customers)) {
        echo "\nFound " . count($customers) . " active customers.\n";
		
		// Initialize progress variables
		$totalCustomers = count($customers);
		$customersProcessed = 0;
		$nextProgressThreshold = 5;

        foreach ($customers as $customer) {
			$customersProcessed++;
			$currentProgress = floor(($customersProcessed / $totalCustomers) * 100);
			
			// Check if the current progress has passed the next threshold
			if ($currentProgress >= $nextProgressThreshold) {
				echo "Processing: " . $currentProgress . "% complete...\n";
				$nextProgressThreshold = $currentProgress + 5;
			}
            // --- Retrieve all internet services for this customer and filter later ---
            if (isset($customer['id'])) {
                $serviceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/internet-services';
                
                // Remove the status filter from the API call, as it's not working properly
                $internetServices = $splynx->get($serviceEndpoint);

                if ($internetServices !== null && !empty($internetServices)) {
                    // Filter the services for active ones manually
                    $activeServicesCount = 0;
                    foreach ($internetServices as $service) {
                        // Check if the service status is 'active' before processing
                        if ($service['status'] === 'active') {
                            $activeServicesCount++;

                            // Initialize variables for the CSV row
                            $planName = 'N/A';
                            $routerTitle = 'N/A';
                            $geoLatitude = 'N/A';
                            $geoLongitude = 'N/A';
                            $installStreetDisplay = 'N/A';
                            $installTownDisplay = 'N/A';

                            // Fetch Internet Plan details
							if (isset($service['tariff_id'])) {
								$tariffEndpoint = 'admin/tariffs/internet/' . $service['tariff_id'];
								$tariffDetails = $splynx->get($tariffEndpoint);
								if($tariffDetails !== null & !empty($tariffDetails))
								{
									$planName = $tariffDetails['title'] ?? 'N/A';
								}
							}
                            
                            // Fetch Router details
							if (isset($service['router_id'])) {
								$routerEndpoint = 'admin/networking/routers/' . $service['router_id'];
								$routerDetails = $splynx->get($routerEndpoint);
								if($routerDetails !== null & !empty($routerDetails))
								{
									$routerTitle = $routerDetails[ 'title'] ?? 'N/A';
								}
							}
						
                            // Retrieve address information
                            $installStreet = $service['additional_attributes']['installstreet'] ?? null;
                            $installTown = $service['additional_attributes']['installtown'] ?? null;
							
							// If the service doesn't have an address, retrieve customer's address	
							$usingCustomerAddress = false;
							if (empty($installStreet)) {
								$installStreet = $customer['street_1'] ?? null;
								$installTown = $customer['city'] ?? null;
								$usingCustomerAddress = true;
							}
								
                            // Check if the service has a street and town
                            $installStreetDisplay = $installStreet ?? '';
                            $installTownDisplay = $installTown ?? '';

                            // Capitalize the first letter of each word in the street and town names
                            $newInstallStreet = $installStreet ? ucwords(strtolower($installStreet)) : null;
                            $newInstallTown = $installTown ? ucwords(strtolower($installTown)) : null;

                            // Flag to determine if a PUT request is needed for additional_attributes
                            $attributesNeedUpdate = false;
                            $attributesToUpdate = ['additional_attributes' => []];						
							
							
							if ($newInstallStreet !== null && ($newInstallStreet !== $installStreet) || $usingCustomerAddress)  {
                                $attributesToUpdate['additional_attributes']['installstreet'] = $newInstallStreet;
                                $attributesNeedUpdate = true;
                                $installStreetDisplay = $newInstallStreet; // Update the display variable
                            }
                            if ($newInstallTown !== null && ($newInstallTown !== $installTown) || $usingCustomerAddress) {
                                $attributesToUpdate['additional_attributes']['installtown'] = $newInstallTown;
                                $attributesNeedUpdate = true;
                                $installTownDisplay = $newInstallTown; // Update the display variable
                            }

                            // If changes were made, send a PUT request to update the additional_attributes
                            if ($attributesNeedUpdate) {
                                $updateEndpoint = 'admin/customers/customer/' . $customer['id'] . '/internet-services--' . $service['id'];
                                $splynx->put($updateEndpoint, $attributesToUpdate);
                            }
                            
                            $initialGeoAddress = $service['geo']['address'] ?? null;
                            $geoMarker = $service['geo']['marker'] ?? null;
                            $updatedGeoAddress = $initialGeoAddress; // Start with the existing geo address

                            // Create a new potential address from the install street and town fields
                            $potentialAddress = '';
                            if (!empty($installStreetDisplay)) {
                                $potentialAddress = $installStreetDisplay;
                                if (!empty($installTownDisplay)) {
                                    $potentialAddress .= ', ' . $installTownDisplay;
                                }
                            }
							
                            $geoUpdateData = ['address' => "",'marker' => "" ];
                            $geoNeedsUpdate = false;

                            // Check if the geo address needs to be updated
                            if (!empty($potentialAddress) && ($updatedGeoAddress === null || $updatedGeoAddress !== $potentialAddress)) {
                                $updatedGeoAddress = $potentialAddress;
                                $geoUpdateData['address'] = $updatedGeoAddress;
                                $geoNeedsUpdate = true;
                            }
							else {
								$geoUpdateData['address'] = $updatedGeoAddress;
							}
                            
                            // Only perform geocoding if address updated or there is no existing geoMarker
                            if ($geoNeedsUpdate || empty($geoMarker)) {
                                $osmCoords = $splynx->getCoordinatesFromAddressOSM($updatedGeoAddress);
                                if ($osmCoords !== null) {
                                    $geoLatitude = $osmCoords['lat'];
                                    $geoLongitude = $osmCoords['lon'];
                                    $newMarker = $geoLatitude . ',' . $geoLongitude;
                                    $geoUpdateData['marker'] = $newMarker;
                                    $geoNeedsUpdate = true;
                                } else {
                                    $googleCoords = $splynx->getCoordinatesFromAddressGoogle($updatedGeoAddress);
                                    if ($googleCoords !== null) {
                                        $geoLatitude = $googleCoords['lat'];
                                        $geoLongitude = $googleCoords['lng'];
                                        $newMarker = $geoLatitude . ',' . $geoLongitude;
                                        $geoUpdateData['marker'] = $newMarker;
                                        $geoNeedsUpdate = true;
                                    } else {
                                        // Fallback to Splynx marker if geocoding is skipped or both APIs fail
                                        if ($geoMarker !== null) {
                                            $coords = explode(',', $geoMarker);
                                            if (count($coords) === 2) {
                                                $geoLatitude = trim($coords[0]);
                                                $geoLongitude = trim($coords[1]);
                                            }
                                        }
                                    }
                                }
                            } else {
                                // Fallback to Splynx marker if geocoding is skipped
                                if ($geoMarker !== null) {
                                    $coords = explode(',', $geoMarker);
                                    if (count($coords) === 2) {
                                        $geoLatitude = trim($coords[0]);
                                        $geoLongitude = trim($coords[1]);
                                    }
                                }
                            }

                            // Perform a single PUT request if any geo data needs to be updated
                            if ($geoNeedsUpdate) {
								$clearData = ['address' => null, 'marker' => null];
								$updateEndpoint = 'admin/customers/customer/' . $customer['id'] . '/geo-internet-service--' . $service['id'];
								$splynx->put($updateEndpoint, $clearData);
								$splynx->put($updateEndpoint, $geoUpdateData);
                            }

                            // Prepare data for the CSV row
                            $rowData = [
                                $customer['id'] ?? '',
                                $customer['name'] ?? '',
                                $customer['login'] ?? '',
                                $customer['email'] ?? '',
                                $customer['status'] ?? '',
                                $planName,
                                $service['status'] ?? '',
                                $service['ipv4'] ?? '',
                                $routerTitle,
                                $installStreetDisplay,
                                $installTownDisplay,
                                $geoLatitude,
                                $geoLongitude
                            ];
                            
                            // Write the row to the CSV file
                            fputcsv($csvFile, $rowData);
                        }
                    }
                }
            }
        }
    } else {
        echo "No active customers found.\n";
    }
} else {
    echo "Failed to retrieve customer data. Please check your API Key, Secret, and Splynx API URL.\n";
}

// Close the CSV file
fclose($csvFile);
echo "\nData successfully written to '{$csvFileName}'.\n";

?>
