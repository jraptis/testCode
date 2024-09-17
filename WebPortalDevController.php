<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\GemhMain;
use App\KAD;
use App\User;
use SSH;
use PDO;
use DB;
use JsonSchema\Validator;
use Sentinel;
use JsonSchema\Constraints\Constraint;
use Mail;
use DateTime;
use ZipArchive;
use DataTables;
use Response;
use Illuminate\Support\Facades\App;
use Lang;
use Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use NumberFormatter;
use \stdClass;


class WebPortalDevController extends Controller
{    
    /**
     * The function accepts a Request with vat,data,api_token,user_role
     * This function handles the evaluation for correct $request body(creds) and get(returns) the data for all levels  the company profile for a speficic vat
     * Returns (JSON)(ARRAY) with company's profile datablock
     */
    public function PersonOverview(Request $request, $plan = null, $class = null)
    {
        // Set Helpers Controller && error 
        $pCont = (new WebPortalProfileController);
        $hasError = false;
        $returnData = null;
        //Set Basic Api call headers
        ini_set('memory_limit', '128M');
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'PersonProfile', ['vat', 'data', 'user_role']);
        $vat = $request["vat"];
        //Set Lang - default el
        $lang = (isset($request["lang"]) && $request["lang"] === 'en') ? 'en' : 'el';
        //Set Safe Check to prevent kyc searched profiles to be added cached companies without preview the profil
        $toBeCached = (isset($request["catchCacheRecord"])) ? $request["catchCacheRecord"] : true;
        //Get the auth token
        $token = $this->bearerToken();
        //Company Section - Level 2
        if ($plan === "2") {
            $this->PersonSection($request, $class);
            //Update DB call status record
            $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
        } elseif ($plan == null) // For all levels new impemention
        {
            //Get APP.Enviroment for Cache
            $environmt = config('keycloak.APP_ENVIROMENT');
            //Dev cache handle to empty cache for the specific vat
            if (isset($request["cache"]) && $request["cache"] == "disable") {
                foreach ($request["data"] as $key => $val) {
                    if ($val["datablock"] == "Basic") {
                        Cache::forget("getPersonKYC" . $vat . "Basic" . $lang . $environmt);
                    }
                    Cache::forget("getPersonKYC" . $vat . $val["datablock"] . $val["level"] . $lang . $environmt);
                }
                Cache::forget("getPersonKYC" . $vat . "summary" . $lang . $environmt);
                Cache::forget("uapitoken");

                ///////////delete existing pdf report ////////////////////////
                $fileName = "KYC-Pro-report-" . $vat . "-" . env("APP_Env") . ".pdf";
                $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                if ($reportFile) {
                    $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                }
                ///////////////////////////////////////////////////////////////////////
            }
            //Set Cached Vat to be updated
            $update = (isset($request["update"]) && $request["update"]) ? true : false;
            //Set to disable curl timeout
            $enableExtended = (isset($request["ttl"]) && $request["ttl"] == "extended") ? true : false;
            //Set Cache Record
            if ($toBeCached) {
                //not now for persons
                // $pCont->handleVatInCachedCompanies($vat);
            }

            //not now for persons
            // $pCont->insertProfileInCacheRecord($vat, $request["data"]);

            //init return data array
            $returnData = array();
            //Get the data for the company 
            $returnData = $pCont->getPersonProfileData($vat, $request["data"], $token, $update, $enableExtended, $request, $lang); //refactor soon to handle errors

            // get update status
            // $status = $helperCont->getUpdateStatus($request["user_email"], $vat);
            //always 2 for persons
            $status = array('update_status_id' => 2,
                'update_status_class' => "info");

            $returnData['data'] = array_merge($returnData['data'], $status);
            $data = $returnData['data'];
            $hasError = $returnData['error'];
            //Check for Error
            if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
                exit();
            //Clean data for unset values
            $results = str_replace(array('"N\/A"', '"-"', ':""'), array("null", "null", ": null"), json_encode($data));
            echo $results;

            //check if afm/user is redeemed
            $lvl1plus = false;
            foreach ($request["data"] as $key => $val) {
                if (isset($val["level"])) {
                    if (intval($val["level"]) >= 1) {
                        $lvl1plus = true;
                    }
                }
            }
            if ($request["user_email"] == "wpdsearchcache@lbsuite.eu" ||
                $request["user_email"] == "test@test.com" ||
                $request["user_email"] == "timetest@test.com" ||
                $request["user_email"] == "Boff@demand.com" ||
                $request["user_email"] == "update@KYCCache.com" ||
                $request["user_email"] == "deliver@profile.com" ||
                isset($request["cache"]) ||
                isset($request["ttl"]) ||
                isset($request["update"]) ||
                isset($request["catchCacheRecord"]) ||
                ! $lvl1plus) {
                // do nothing 
            } else {
                $helperCont->checkRedeemed($request["vat"], $request["user_email"], $lang, $request["api_token"]);
            }

            if ($lvl1plus==false && $request["user_email"]!="wpdsearchcache@lbsuite.eu" && $request["user_email"] != "wpdsearchcache@lbsuite.eu" &&
            $request["user_email"] != "test@test.com" && $request["user_email"] != "timetest@test.com" && $request["user_email"] != "Boff@demand.com" &&
            $request["user_email"] != "update@KYCCache.com" && $request["user_email"] != "deliver@profile.com") {
                $mes = array();
                $mes["Ownership"] = array();
                array_push($mes["Ownership"],array("vat"=>$vat,"isCompany"=>"0"));
                try {
                    $cachedData = ['token' => $request['api_token'], 'mes' => $mes, "lang" => $lang, "trigger" => "lvl0"];
                    Cache::put("kycsearchedprofile_tobecached", $cachedData, config("keycloak.KYCCacheTime"));
                    $helperCont->triggerRunProcs();
                } catch (Exception $e) {
                    Log::error('KYC Level 0 - Cached search results trigger failed with error: ' . $e->getMessage());
                }
            }

            //Update Cached Record Counter/ttl
            $pCont->updateVisitProfileInCacheRecord($vat, $request["data"], $update, $returnData['visitedBlocks']);
            //Update DB call status record
            $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
            exit();
        }

    }
    /**
     * The function accepts a Request with vat,data,api_token,user_role
     * This function handles the evaluation for correct $request body(creds) and get(returns) the data for all levels  the company profile for a speficic vat
     * Returns (JSON)(ARRAY) with company's profile datablock
     */
    public function CompanyOverview(Request $request, $plan = null, $class = null)
    {
        // Set Helpers Controller && error 
        $time_start = microtime(true);
        $pCont = (new WebPortalProfileController);
        $hasError = false;
        $returnData = null;
        //Set Basic Api call headers
        ini_set('memory_limit', '128M');
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'CompanyProfile', ['vat', 'data', 'user_role']);
        $vat = $request["vat"];
        //Set Lang - default el
        $lang = (isset($request["lang"]) && $request["lang"] === 'en') ? 'en' : 'el';
        //Set Safe Check to prevent kyc searched profiles to be added cached companies without preview the profil
        $toBeCached = (isset($request["catchCacheRecord"])) ? $request["catchCacheRecord"] : true;
        //Get the auth token
        $token = $this->bearerToken();
        //Company Section - Level 2
        if ($plan === "2") {
            $this->CompanySection($request, $class);
            //Update DB call status record
            $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
        } elseif ($plan == null) // For all levels new impemention
        {
            //Get APP.Enviroment for Cache
            $environmt = config('keycloak.APP_ENVIROMENT');
            //Dev cache handle to empty cache for the specific vat
            if (isset($request["cache"]) && $request["cache"] == "disable") {
                foreach ($request["data"] as $key => $val) {
                    if ($val["datablock"] == "Basic") {
                        Cache::forget("getCompanyKYC" . $vat . "Basic" . $lang . $environmt);
                    }
                    Cache::forget("getCompanyKYC" . $vat . $val["datablock"] . $val["level"] . $lang . $environmt);
                }
                Cache::forget("getCompanyKYC" . $vat . "summary" . $lang . $environmt);
                Cache::forget("uapitoken");

                ///////////delete existing pdf report ////////////////////////
                $fileName = "KYC-Pro-report-" . $vat . "-" . env("APP_Env") . ".pdf";
                $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                if ($reportFile) {
                    $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                }
                ///////////////////////////////////////////////////////////////////////
            }
            //Set Cached Vat to be updated
            $update = (isset($request["update"]) && $request["update"]) ? true : false;
            //Set to disable curl timeout
            $enableExtended = (isset($request["ttl"]) && $request["ttl"] == "extended") ? true : false;
            //Set Cache Record
            if ($toBeCached)
                $pCont->handleVatInCachedCompanies($vat);
            $pCont->insertProfileInCacheRecord($vat, $request["data"]);
            //init return data array
            $returnData = array();
            //Get the data for the company 
            $returnData = $pCont->getCompanyProfileData($vat, $request["data"], $token, $update, $enableExtended, $request, $lang); //refactor soon to handle errors
            // get update status
            // Always 2 if FR
            if (isset($returnData["data"]["org_type"]) && ($returnData["data"]["org_type"] == "ΦΥΣΙΚΟ ΠΡΟΣΩΠΟ" || $returnData["data"]["org_type"] == "FR")) {
                $status = array('update_status_id' => 2,
                    'update_status_class' => "info");
            } else {
                $status = $helperCont->getUpdateStatus($request["user_email"], $vat);
            }
            $returnData['data'] = array_merge($returnData['data'], $status);
            $data = $returnData['data'];
            $hasError = $returnData['error'];
            //Check for Error
            if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
                exit();


            $data = $this->classDataResultsOverviewParametric($data, $request["data"]);

            //Clean data for unset values
            $results = str_replace(array('"N\/A"', '"-"', ':""'), array("null", "null", ": null"), json_encode($data));
            echo $results;

            //check if afm/user is redeemed
            $lvl1plus = false;
            foreach ($request["data"] as $key => $val) {
                if (isset($val["level"])) {
                    if (intval($val["level"]) >= 1) {
                        $lvl1plus = true;
                    }
                }
            }
            if ($request["user_email"] == "wpdsearchcache@lbsuite.eu" ||
                $request["user_email"] == "test@test.com" ||
                $request["user_email"] == "timetest@test.com" ||
                $request["user_email"] == "Boff@demand.com" ||
                $request["user_email"] == "update@KYCCache.com" ||
                $request["user_email"] == "deliver@profile.com" ||
                isset($request["cache"]) ||
                isset($request["ttl"]) ||
                isset($request["update"]) ||
                isset($request["catchCacheRecord"]) ||
                ! $lvl1plus) {
                // do nothing 
            } else {
                $helperCont->checkRedeemed($request["vat"], $request["user_email"], $lang, $request["api_token"]);
            }

            if ($lvl1plus==false && $request["user_email"]!="wpdsearchcache@lbsuite.eu" && $request["user_email"] != "wpdsearchcache@lbsuite.eu" &&
            $request["user_email"] != "test@test.com" && $request["user_email"] != "timetest@test.com" && $request["user_email"] != "Boff@demand.com" &&
            $request["user_email"] != "update@KYCCache.com" && $request["user_email"] != "deliver@profile.com") {
                $mes = array();
                $mes["Companies"] = array();
                array_push($mes["Companies"],array("vat"=>$vat,"isCompany"=>"1"));
                try {
                    $cachedData = ['token' => $request['api_token'], 'mes' => $mes, "lang" => $lang, "trigger" => "lvl0"];
                    Cache::put("kycsearchedprofile_tobecached", $cachedData, config("keycloak.KYCCacheTime"));
                    $helperCont->triggerRunProcs();
                } catch (Exception $e) {
                    Log::error('KYC Level 0 - Cached search results trigger failed with error: ' . $e->getMessage());
                }
            }

            //Update Cached Record Counter/ttl
            $pCont->updateVisitProfileInCacheRecord($vat, $request["data"], $update, $returnData['visitedBlocks']);
            //Update DB call status record
            $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
            $time_end = microtime(true);
            $execution_time = $time_end - $time_start;
            Log::info('Total Execution Time for vat: ' . $vat . ' is: ' . $execution_time);
            exit();
        }
    }


    public function classDataResultsOverviewParametric($results, $data)
    {
        // Testing code for class inside a datablock level data
        // foreach ($data as $k => $v) {
        //     if (isset($v["class"]) && intval($v["level"]) == 2) {
        //         if ($v["datablock"] == "Money" && intval($v["class"]) == 1 && isset($results["money"])) {
        //             if (isset($results["money"]["diavgeia"])) {
        //                 foreach ($results["money"]["diavgeia"] as $k2 => $v2) {
        //                     foreach ($v2 as $k3 => $v3) {
        //                         if (isset($v3["signed_date"]) && $v3["signed_date"] <= "2022-12-31") {
        //                             unset($results["money"]["diavgeia"][$k2][$k3]);
        //                         }
        //                     }

        //                 }
        //             }

        //         }

        //     }
        // }

        return $results;
    }
    /**
     * The function accepts a Request with vat,data,api_token,user_role
     * This function handles the evaluation for correct $request body(creds) and get(returns) the data for the company profile lv2 for a speficic vat
     * Returns (JSON)(ARRAY) with company's profile datablock
     */
    public function CompanySection(Request $request, $class = null)
    {
        //Set Helpers Controller && error 
        $helperCont = (new WebPortalHelperControllerV1);
        $pCont = (new WebPortalProfileController);
        $hasError = false;
        http_response_code(200);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'CompanyProfile', ['vat', 'data']);
        $vat = $request["vat"];
        //Set Safe Check to prevent kyc searched profiles to be added cached companies without preview the profil
        $toBeCached = (isset($request["catchCacheRecord"])) ? $request["catchCacheRecord"] : true;
        //Get APP.Enviroment for Cache
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Check to clear Cache
        if (isset($request["cache"]) && $request["cache"] == "disable") {
            foreach ($request["data"] as $k => $val) {
                Cache::forget("getCompanyKYC" . $vat . $val . "2" . $environmt);
            }
            Cache::forget("getCompanyKYC" . $vat . "summary" . $environmt);
            Cache::forget("uapitoken");
        }
        //Set Lang - default el
        $lang = (isset($request["lang"]) && $request["lang"] === 'en') ? 'en' : 'el';
        //Set Cached Vat to be updated
        $update = (isset($request["update"]) && $request["update"]) ? true : false;
        //Set to disable curl timeout
        $enableExtended = (isset($request["ttl"]) && $request["ttl"] == "extended") ? true : false;
        //Set data Array and get Token for the service calls
        $resultsData = array();
        $token = $this->bearerToken();
        //Get Datablocks format
        $requestData = $pCont->getRequestDatablocksForCompanyProfileData($request["data"], $class);
        //Set Cache Record
        if ($toBeCached)
            $pCont->handleVatInCachedCompanies($vat);
        $pCont->insertProfileInCacheRecord($vat, $requestData);
        //Get Company Profile Data
        $resultsData = $pCont->getCompanyProfileData($vat, $requestData, $token, $update, $enableExtended, $request, $lang);
        $data = $resultsData['data'];
        $hasError = $resultsData['error'];
        //Check for Error
        if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
            exit();

        $data = $this->classDataResultsOverviewParametric($data, $requestData);
        //Replace Invalid json values [ N/A, - , "" ]
        $results = str_replace(array('"N\/A"', '"-"', ':""'), array("null", "null", ": null"), json_encode($data));
        //Return Responses
        echo $results;
        //Update Cached Record Counter/ttl
        $pCont->updateVisitProfileInCacheRecord($vat, $request["data"], $update, $resultsData['visitedBlocks']);
        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
        exit();
    }

    /**
     * The function accepts a Request with vat,data,api_token,user_role
     * This function handles the evaluation for correct $request body(creds) and get(returns) the data for the company profile lv2 for a speficic vat
     * Returns (JSON)(ARRAY) with company's profile datablock
     */
    public function PersonSection(Request $request, $class = null)
    {
        //Set Helpers Controller && error 
        $helperCont = (new WebPortalHelperControllerV1);
        $pCont = (new WebPortalProfileController);
        $hasError = false;
        http_response_code(200);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'CompanyProfile', ['vat', 'data']);
        $vat = $request["vat"];
        //Set Safe Check to prevent kyc searched profiles to be added cached companies without preview the profil
        $toBeCached = (isset($request["catchCacheRecord"])) ? $request["catchCacheRecord"] : true;
        //Get APP.Enviroment for Cache
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Check to clear Cache
        if (isset($request["cache"]) && $request["cache"] == "disable") {
            foreach ($request["data"] as $k => $val) {
                Cache::forget("getPersonKYC" . $vat . $val . "2" . $environmt);
            }
            Cache::forget("getPersonKYC" . $vat . "summary" . $environmt);
            Cache::forget("uapitoken");
        }
        //Set Lang - default el
        $lang = (isset($request["lang"]) && $request["lang"] === 'en') ? 'en' : 'el';
        //Set Cached Vat to be updated
        $update = (isset($request["update"]) && $request["update"]) ? true : false;
        //Set to disable curl timeout
        $enableExtended = (isset($request["ttl"]) && $request["ttl"] == "extended") ? true : false;
        //Set data Array and get Token for the service calls
        $resultsData = array();
        $token = $this->bearerToken();
        //Get Datablocks format
        $requestData = $pCont->getRequestDatablocksForCompanyProfileData($request["data"], $class);
        //Set Cache Record
        if ($toBeCached) {

            // not now for persons
            // $pCont->handleVatInCachedCompanies($vat);

        }

        // not now for persons
        //$pCont->insertProfileInCacheRecord($vat, $requestData);

        //Get Company Profile Data
        $resultsData = $pCont->getPersonProfileData($vat, $requestData, $token, $update, $enableExtended, $request, $lang);

        $data = $resultsData['data'];
        $hasError = $resultsData['error'];
        //Check for Error
        if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
            exit();
        //Replace Invalid json values [ N/A, - , "" ]
        $results = str_replace(array('"N\/A"', '"-"', ':""'), array("null", "null", ": null"), json_encode($data));
        //Return Responses
        echo $results;

        //Update Cached Record Counter/ttl
        // not now for persons
        // $pCont->updateVisitProfileInCacheRecord($vat, $request["data"], $update, $resultsData['visitedBlocks']);

        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
        exit();
    }
    /**
     * The function accepts a Request with search keyword & search type
     * This functions based on search type returns all relevants to search keyword, companies,persons or same address companies 
     * Returns (JSON)(ARRAY) with persons(Ownership) or companies(Companies or SameAddress)
     */
    public function KYCSearch(Request $request)
    {
        //Set Helpers Controller && error & response settings
        $helperCont = (new WebPortalHelperControllerV1);
        $token = $this->bearerToken();
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'KycSearch', ['keyword', 'type']);
        //Set request/response variables & helpers
        $environmt = config('keycloak.APP_ENVIROMENT');
        $keyword = trim($request["keyword"]);
        $type = $request["type"];
        $mes = array();
        App::setLocale("gr");
        $scont = (new KYCSearchController);
        //Error handling for not valid vat or gemh
        if (! $scont->validateNumber($keyword, $idc1))
            exit();
        //Set Lang
        $lang = isset($request['lang']) && $request['lang'] === 'en' ? 'en' : 'el';
        // $fake = isset($request['fake']) && $request['fake'] === 'yes' ? true : false;
        //Handling for type search Companies (Accepts only vats)
        if ("Companies" == $type) {
            // Init Results
            $keyword = urlencode($keyword);
            $curl_elastic = curl_init();
            curl_setopt_array($curl_elastic, array(
                CURLOPT_URL => config('keycloak.Elastic_Company') . '?searchTerm=' . $keyword . "&lang=" . $lang,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    "Accept: */*",
                    "Accept-Encoding: gzip, deflate, br",
                    "Authorization: Bearer " . $token
                ),
            ));
            $response = curl_exec($curl_elastic);
            $mes["Companies"] = json_decode($response, true);
            foreach ($mes["Companies"] as $key => $company) {
                if (isset($company['topCpaName'])) {
                    $company['mainCpaName'] = $company['topCpaName'];
                    unset($company['topCpaName']);
                } else {
                    $company['mainCpaName'] = null;
                }
                if (isset($company['market'])) {
                    $company['marketName'] = $company['market'];
                    unset($company['market']);
                } else {
                    $company['marketName'] = null;
                }


                if (isset($company['grossProfitAmount'])) {
                    $company['turnOverAmount'] = $company['grossProfitAmount'] . "";
                    unset($company['grossProfitAmount']);
                } else {
                    $company['turnOverAmount'] = null;
                    if (array_key_exists('grossProfitAmount', $company)) {
                        unset($company['grossProfitAmount']);
                    }

                }


                if (isset($company['grossProfitYear'])) {
                    $company['turnOverYear'] = $company['grossProfitYear'];
                    unset($company['grossProfitYear']);
                } else {
                    $company['turnOverYear'] = null;
                    if (array_key_exists('grossProfitYear', $company)) {
                        unset($company['grossProfitYear']);
                    }

                }

                $company['gemh'] = $company['gemhNumber'];
                unset($company['gemhNumber']);
                $company['isActive'] = $company['active'];
                unset($company['active']);
                $company['score'] = 'high';
                $company['email'] = null;
                $company['website'] = null;
                $company['phone'] = null;
                $company['office'] = null;
                $mes["Companies"][$key] = $company;
            }

            // Make the search with keyword and type Companies==3
            // try {
            //     if ($request["cache"] != "disable" && Cache::has("kycsearch-" . $keyword . $type . $lang . $environmt)) {
            //         $mes["Companies"] = Cache::get("kycsearch-" . $keyword . $type . $lang . $environmt)[0];

            //         if (! $fake) {
            //             $datetime1 = new DateTime(Cache::get("kycsearch-" . $keyword . $type . $lang . $environmt)[1]);
            //             $datetime2 = new DateTime(date("Y-m-d H:i:s"));
            //             $difference = $datetime1->diff($datetime2);
            //             if ($difference->y > 0 || $difference->m > 0 || $difference->d > 1) {
            //                 $body = [
            //                     "api_token" => $request['api_token'],
            //                     "keyword" => $keyword,
            //                     "type" => $type,
            //                     "lang" => $lang,
            //                     "cache" => "disable",
            //                     "fake" => "yes"
            //                 ];
            //                 $post = json_encode($body);
            //                 $curl = curl_init();
            //                 curl_setopt_array($curl, array(
            //                     CURLOPT_URL => env("APP_URL_inside") . "/api/v1/WebPortal/search",
            //                     CURLOPT_RETURNTRANSFER => true,
            //                     CURLOPT_ENCODING => "",
            //                     CURLOPT_MAXREDIRS => 10,
            //                     CURLOPT_TIMEOUT_MS => 5,
            //                     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //                     CURLOPT_CUSTOMREQUEST => "POST",
            //                     CURLOPT_POSTFIELDS => $post,
            //                     CURLOPT_HTTPHEADER => array(
            //                         "accept: */*",
            //                         "Content-Type: application/json"
            //                     ),
            //                 ));
            //                 curl_exec($curl);
            //             }
            //         }

            //     } else {
            //         $isSearchbyVatGemh = is_numeric($keyword) ? true : false; //temp rule to by pass null field restriction, furthermore to keep it in cache
            //         $res = $scont->searchForm($keyword, 3);
            //         if (isset($request["couch"])) {
            //             if ($request["couch"] == "only") {
            //                 echo $res;
            //                 exit();
            //             }
            //         }
            //         $data = json_decode($res, true);

            //         //Error handling for not found vat
            //         if (count($data) == 0 || (isset($data[0]) && $data[0]["id"] == "not_assigned_vat")) {
            //             http_response_code(400);
            //             $mes = [
            //                 "errors" => [
            //                     [
            //                         "title" => "vat number(s) not valid: <vat numbers>",
            //                         "error_code" => 8
            //                     ]
            //                 ]
            //             ];
            //             Log::info("KYC Search - Company - Not found any vat/gemh or name with keyword: {$keyword}");
            //         } else {
            //             //Get Results Clean&Formatted   
            //             // return $data;                 
            //             // $dataSearch = $scont->getCleanCompaniesResults($data, $lang, $isSearchbyVatGemh);  

            //             if ($lang == "en") {
            //                 $langparse = "_en";
            //             } else {
            //                 $langparse = "";
            //             }
            //             $dataSearch = array();
            //             $discomp = array();
            //             foreach ($data as $k => $v) {
            //                 $fdata = $scont->companyData($v, $langparse, count($data));
            //                 if (! in_array($fdata["vat"], $discomp)) {
            //                     array_push($dataSearch, $fdata);
            //                     array_push($discomp, $fdata["vat"]);
            //                 }
            //             }
            //             //   return $dataSearch;
            //             $vatlist = array();
            //             foreach ($data as $k => $v) {
            //                 if (isset($v["vat"])) {
            //                     if (! in_array($v["vat"], $vatlist) && strpos($v["vat"], "BID") === false) {
            //                         array_push($vatlist, $v["vat"]);
            //                     }
            //                 }
            //             }

            //             $mes = $this->getCompaniesDataforSearched($vatlist, $lang, $isSearchbyVatGemh, $dataSearch);
            //             if (count($mes["Companies"]) > 2) {
            //                 Cache::put("kycsearch-" . $keyword . $type . $lang . $environmt, array($mes["Companies"], date("Y-m-d H:i:s")), config("keycloak.KYCSearchCacheTime"));
            //             }
            //         }
            //     }
            // } catch (Exception $e) {
            //     Log::error("KYC Search - Failed to get data Companies for keyword search: {$keyword}. Error: " . $e->getMessage());
            //     echo $e->getMessage();
            // }
        }
        //Handling for type search Ownership
        if ("Ownership" == $type) {

            $keyword = urlencode($keyword);
            $curl_elastic = curl_init();
            curl_setopt_array($curl_elastic, array(
                CURLOPT_URL => config('keycloak.Elastic_Person') . '?searchTerm=' . $keyword,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    "Accept: */*",
                    "Accept-Encoding: gzip, deflate, br",
                    "Authorization: Bearer " . $token
                ),
            ));
            $response = curl_exec($curl_elastic);
            $mes["Ownership"] = json_decode($response, true);
            foreach ($mes["Ownership"] as $key => $company) {

                if (isset($company['isCompany'])) {
                    $company['isCompany'] = $company['isCompany'] == true ? "1" : "0";
                } else {
                    $company['isCompany'] = "0";
                }
                $company['score'] = 'high';

                $mes["Ownership"][$key] = $company;
            }

            $mes["Ownership"] = $this->formatCleanOwnershipResults($mes["Ownership"]);


            //Init Results
            // $mes["Ownership"] = array();
            // // Make the search with keyword and type Onwership==1 (aka persons)
            // try {
            //     $isSearchbyVatGemh = is_numeric($keyword) ? true : false; //temp rule to by pass null field restriction

            //     $res = $scont->searchForm($keyword, 1);
            //     $data = json_decode($res, true);
            //     //Error handling if keyword isn't found
            //     if (count($data) == 0 || (isset($data[0]) && $data[0]["id"] == "not_person_vat")) {
            //         http_response_code(400);
            //         $mes = [
            //             "errors" => [
            //                 [
            //                     "title" => "vat number(s) not valid: <vat numbers>",
            //                     "error_code" => 8
            //                 ]
            //             ]
            //         ];
            //         Log::info("KYC Search - Onwership - Not found any vat/gemh with keyword: {$keyword}");
            //     } else {
            //         //Get Results Sorted&Formatted
            //         $mes_t = $scont->getOnwershipResults($data, $lang, $isSearchbyVatGemh);
            //         $mes["Ownership"] = $this->formatCleanOwnershipResults($mes_t);
            //     }
            // } catch (Exception $e) {
            //     Log::error("KYC Search - Failed to get data Onwership for keyword search: {$keyword}. Error: " . $e->getMessage());
            // }
        }
        //Handling for type search SameAddress (Accept only vats)
        if ("SameAddress" == $type) { //Defualt response
            $mes["SameAddress"] = array();
            //Error handling if keyword isn't number(vat)
            if (! is_numeric($keyword)) {
                http_response_code(400);
                $mes = [
                    "errors" => [
                        [
                            "title" => "vat number(s) not valid: <vat numbers>",
                            "error_code" => 8
                        ]
                    ]
                ];
                Log::info("KYC Search - SameAddress - Request input is not vat keyword: {$keyword}");
            } else {
                //Prepare Curl Request
                $body = [
                    "vat" => str_replace(" ", "", $keyword)
                ];
                
                $hasError = false;
                //Get Auth token
                $token = $this->bearerToken();
                //CURL request to SameAddress submit endpoint with a vat
                $post = json_encode($body);
                $curl = $this->preparePostCurl(config("keycloak.KYCSearchSameAddress"), $token, $post, 45);
                $res = curl_exec($curl);
                $err = curl_error($curl);
                //Error handling if service(curl) isn't availiable(went something wrong)
                if ($err) {
                    $hasError = true;
                    Log::error("KYC Search - Failed to get Response from Name-Matching Service. Vat: '.$keyword.'. Error occurred with error message: " . json_encode($err));
                }
                //Check for Error
                if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
                    exit();
                //Get Response with application code
                $data = json_decode($res, true);
                //Get Matched Address Companies
                if (isset($data["matched_addresses"]) && count($data["matched_addresses"]) > 0) {
                    //Get Vats
                    $vat_list = $scont->getSameAddressVatList($data, $data["input_vat"]);
                    //Prepare Request Body for Watchlist
                    $post = [
                        "vat" => $vat_list,
                    ];
                    $post = json_encode($post);
                    //CURL request to Watchlist endpoint to get for all vats in list data
                    $curlWatchlist = $this->preparePostCurl(config("keycloak.watchlist") . '?lang=' . $lang, $token, $post, 15);
                    $resp = curl_exec($curlWatchlist);
                    $err = curl_error($curlWatchlist);
                    curl_close($curlWatchlist);
                    //Check for Error
                    if ($err) {
                        $hasError = true;
                        Log::error("KYC Search - Failed to get Response from Watchlist Service. Vat: '.$keyword.'. Error occurred with error message: " . json_encode($err));
                    }
                    if ($helperCont->handleErrors($idc1, 'webportal_calls', $hasError))
                        exit();
                    //Decode Response
                    $respWatchlist = json_decode($resp, true);
                    //Get Data
                    try {
                        $mes["SameAddress"] = $scont->getKycSameAddressData($respWatchlist["Companies"]);
                    } catch (Exception $e) {
                        Log::error('KYC Search - SameAddress - Failed to get data from watchlist for vat list: ' . json_encode($vat_list) . ' with error ' . $e->getMessage());
                    }
                } else {
                    Log::error("KYC Search - Same Address No data found from Name-Matching Service. Vat: '.$keyword.'. Error occurred with error message: " . json_encode($err));
                }
            }
        }
        //Start Cache Companies Profiles for first 5 results 
        try {
            $cachedData = ['token' => $request['api_token'], 'mes' => $mes, "lang" => $lang, "trigger" => "search"];
            Cache::put("kycsearchedprofile_tobecached", $cachedData, config("keycloak.KYCCacheTime"));
            $helperCont->triggerRunProcs();
        } catch (Exception $e) {
            Log::error('KYC Search - Cached search results trigger failed with error: ' . $e->getMessage());
        }
        //Show Results
        echo json_encode($mes);
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Search');
        exit();
    }

    // this function returns companies data from gsis watchlist service (and S3) for search when $datasearch contains data from couch db search, 
    // elseif no data from couch then returns the format for watchlists
    public function getCompaniesDataforSearched($vatlist, $lang, $isSearchbyVatGemh = false, $dataSearch = array())
    {
        $vatlist_start = $vatlist;
        $scont = (new KYCSearchController);
        $dataS = $this->getDataCompaniesFromS3($vatlist, $lang);
        $vatlist = $dataS[0];

        if (count($dataSearch) > 0) {
            if (count($vatlist) > 0) {
                $dataW = $this->getDataFromGsisWatchlist($vatlist, $lang, "search");
            } else {
                $dataW["Companies"] = array();
            }
            $dataM = $this->dataMergeFromSearchAndWatchlist($dataSearch, $dataW["Companies"], $dataS[1]);
            $dataC = $scont->getCleanCompaniesResults($dataM, $lang, $isSearchbyVatGemh);
            $mes["Companies"] = $this->reorderKYCSearchResults($dataC);
        } else {
            if (count($vatlist) > 0) {
                $dataW = $this->getDataFromGsisWatchlist($vatlist, $lang, "default");
            } else {
                $dataW["Companies"] = array();
            }
            $dataM = $this->dataMergeFromWatchlist($vatlist_start, $dataW["Companies"], $dataS[1]);
            $dataC = $this->getCleanCompaniesResultsCompanyList($dataM);
            $mes["Companies"] = $dataC;
        }

        $mes["Companies"] = $this->delNullCompanies($mes["Companies"]);

        return $mes;
    }

    public function delNullCompanies($ar)
    {
        $tar = array();
        foreach ($ar as $k => $v) {
            $add = false;
            foreach ($v as $k2 => $v2) {
                if ($v2 != null)
                    $add = true;
                break;
            }
            if ($add)
                array_push($tar, $v);
        }
        return $tar;
    }

    public function getPersonsDataforSearched($vatlist, $lang)
    {
        $scont = (new KYCSearchController);
        $mes = array();
        foreach ($vatlist as $k => $v) {
            $isSearchbyVatGemh = is_numeric($v) ? true : false; //temp rule to by pass null field restriction
            $res = $scont->searchForm($v, 1);
            $data = json_decode($res, true);
            if ($data[0]["id"] == $v && $data[0]["isCompany"] != 1) {
                $mes_t = $scont->getOnwershipResults($data, $lang, $isSearchbyVatGemh);
                array_push($mes, $this->formatCleanOwnershipResults($mes_t)[0]);
            }
        }
        return $mes;
    }

    public function getCleanCompaniesResultsCompanyList($d)
    {
        $comp = array();

        $fieldsToCheck1 = ["name", "vat", "gemh", "address", "orgType", "mainCpaName", "marketName", "isActive", "isDebtor", "hasAuctions", "hasFines", "turnOverAmount", "turnOverYear", "incorporationDate"];
        $fieldsToCheck2 = ["name", "vat", "gemhNumber", "address", "orgType", "topCpaName", "marketName", "active", "isDebtor", "hasAuctions", "hasFines", "turnOverAmount", "turnOverYear", "incorporationDate"];
        foreach ($d as $k => $v) {
            $entry = array();
            foreach ($fieldsToCheck2 as $k2 => $v2) {
                if (isset($v[$v2])) {
                    $entry[$fieldsToCheck1[$k2]] = $v[$v2];
                } else {
                    $entry[$fieldsToCheck1[$k2]] = null;
                }
            }
            array_push($comp, $entry);
        }
        return $comp;
    }

    public function formatCleanOwnershipResults($pers)
    {
        $keys_array = array("name", "vat", "managementCnt", "ownershipCnt", "score", "isCompany", "isDebtor", "hasAuctions", "hasFines");
        foreach ($pers as $k => $v) {
            foreach ($v as $k2 => $v2) {
                if (! in_array($k2, $keys_array)) {
                    unset($pers[$k][$k2]);
                }
            }
            if (! isset($pers[$k]["isDebtor"]))
                $pers[$k]["isDebtor"] = false;
            if (! isset($pers[$k]["hasAuctions"]))
                $pers[$k]["hasAuctions"] = false;
            if (! isset($pers[$k]["hasFines"]))
                $pers[$k]["hasFines"] = false;
        }
        return $pers;
    }

    public function getDataCompaniesFromS3($vatlist, $lang)
    {
        $notfound = array();
        $datac = array();
        foreach ($vatlist as $k => $v) {
            //lb.platform/watchlist/[production OR staging]/{vat}/{language}.json
            if (env("APP_Env") == "staging") {
                $envir = "staging/";
            } else {
                $envir = "production/";
            }

            $s3filepath = config("keycloak.Aws_Folder_Watchlist") . $envir . $v . "/" . $lang . ".json";
            $reportFile = Storage::disk('s3-kycsearch')->exists($s3filepath);
            if ($reportFile) {
                $filedata = json_decode(Storage::disk('s3-kycsearch')->get($s3filepath), true);
                if (isset($filedata["vat"])) {
                    array_push($datac, $filedata);
                }
            } else {
                array_push($notfound, $v);
            }
        }
        return array($notfound, $datac);
    }

    public function reorderKYCSearchResults($sres)
    {
        $perturnover = array();
        $res = array();
        $countres = count($sres);
        for ($i = 0; $i < $countres; $i++) {
            $lm = -1;
            foreach ($sres as $k => $v) {
                if ($v["turnOverAmount"] != null && intval($v["turnOverAmount"]) > $lm) {
                    $t = $v;
                    $ti = $k;
                    $lm = intval($v["turnOverAmount"]);
                }
            }
            if ($lm >= 0) {
                array_push($perturnover, $t);
                unset($sres[$ti]);
            }
        }
        foreach ($perturnover as $v) {
            array_push($res, $v);
        }
        foreach ($sres as $v) {
            array_push($res, $v);
        }
        $sres = $res;
        $tmp = $sres;
        $res = array();
        foreach ($tmp as $k => $v) {
            if ($v["orgType"] == "ΑΕ") {
                array_push($res, $v);
                unset($sres[$k]);
            }
        }
        $tmp = $sres;
        foreach ($tmp as $k => $v) {
            if ($v["turnOverAmount"] != null) {
                array_push($res, $v);
                unset($sres[$k]);
            }
        }
        $tmp = $sres;
        foreach ($tmp as $k => $v) {
            if ($v["orgType"] != "Ατομική") {
                array_push($res, $v);
                unset($sres[$k]);
            }
        }
        $tmp = $sres;
        foreach ($tmp as $k => $v) {
            array_push($res, $v);
            unset($sres[$k]);
        }
        return $res;
    }

    public function dataMergeFromSearchAndWatchlist($dataSearch = null, $dataW = null, $dataS = null)
    {
        $new = array();
        foreach ($dataSearch as $k1 => $v1) {
            $entry = $v1;
            $found = false;
            foreach ($dataW as $k2 => $v2) {
                if ($v1["vat"] == $v2["vat"]) {
                    foreach ($v1 as $k3 => $v3) {
                        if (isset($v2[$k3])) {
                            $entry[$k3] = $v2[$k3];
                        }
                    }
                    if (isset($v2["gemhNumber"]))
                        $entry["gemh"] = $v2["gemhNumber"];
                    // if (isset($v2["market"]))
                    $entry["marketName"] = $v2["market"];
                    // if (isset($v2["active"]))
                    $entry["isActive"] = $v2["active"];

                    if (isset($v2["turnOverAmount"]))
                        $entry["turnOverAmount"] = $v2["turnOverAmount"] . "";
                    else
                        $entry["turnOverAmount"] = null;

                    if (isset($v2["turnOverYear"]))
                        $entry["turnOverYear"] = $v2["turnOverYear"];
                    else
                        $entry["turnOverYear"] = null;

                    if (isset($v2["hasEfka_or_Public_Debt"]))
                        $entry["isDebtor"] = $v2["hasEfka_or_Public_Debt"];
                    else
                        $entry["isDebtor"] = false;


                    $found = true;
                    break;
                }
            }
            if (! $found) {
                foreach ($dataS as $k2 => $v2) {
                    if ($v1["vat"] == $v2["vat"]) {
                        foreach ($v1 as $k3 => $v3) {
                            if (isset($v2[$k3])) {
                                $entry[$k3] = $v2[$k3];
                            }
                        }
                        if (isset($v2["gemhNumber"]))
                            $entry["gemh"] = $v2["gemhNumber"];
                        // if (isset($v2["market"]))
                        $entry["marketName"] = $v2["market"];
                        // if (isset($v2["active"]))
                        $entry["isActive"] = $v2["active"];

                        if (isset($v2["turnOverAmount"]))
                            $entry["turnOverAmount"] = $v2["turnOverAmount"] . "";
                        else
                            $entry["turnOverAmount"] = null;

                        if (isset($v2["turnOverYear"]))
                            $entry["turnOverYear"] = $v2["turnOverYear"];
                        else
                            $entry["turnOverYear"] = null;

                        if (isset($v2["hasEfka_or_Public_Debt"]))
                            $entry["isDebtor"] = $v2["hasEfka_or_Public_Debt"];
                        else
                            $entry["isDebtor"] = false;

                        $found = true;
                        break;
                    }
                }
            }
            array_push($new, $entry);
        }
        return $new;
    }

    public function dataMergeFromWatchlist($vatlist = null, $dataW = null, $dataS = null)
    {
        $new = array();
        foreach ($vatlist as $k1 => $v1) {
            $entry = array();
            $found = false;
            if (count($dataW) > 0) {
                foreach ($dataW as $k2 => $v2) {
                    if ($v1 == $v2["vat"]) {
                        $entry = $v2;
                        // if (isset($v2["market"]))
                        $entry["marketName"] = $v2["market"];
                        // if (isset($v2["active"]))
                        $entry["isActive"] = $v2["active"];
                        // if (isset($v2["turnOverAmount"]))

                        if (isset($v2["turnOverAmount"]))
                            $entry["turnOverAmount"] = $v2["turnOverAmount"] . "";
                        else
                            $entry["turnOverAmount"] = null;

                        if (isset($v2["turnOverYear"]))
                            $entry["turnOverYear"] = $v2["turnOverYear"];
                        else
                            $entry["turnOverYear"] = null;

                        if (isset($v2["mainCpaName"]) && $v2["mainCpaName"] != null) {
                            $entry["mainCpaName"] = $v2["mainCpaName"];
                        } elseif (isset($v2["topCpaName"]) && $v2["topCpaName"] != null) {
                            $entry["mainCpaName"] = $v2["topCpaName"];
                        }

                        if (isset($v2["gemhNumber"]) && $v2["gemhNumber"] != null) {
                            $entry["gemh"] = $v2["gemhNumber"];
                        }

                        if (isset($v2["ebit"]) && $v2["ebit"] != null) {
                            $entry["earnings"] = $v2["ebit"];
                        }

                        if (isset($v2["chamber"]) && $v2["chamber"] != null) {
                            $entry["office"] = $v2["chamber"];
                        }

                        if (isset($v2["orgType_category"]) && $v2["orgType_category"] != null) {
                            $entry["orgType"] = $v2["orgType_category"];
                        }

                        if (isset($v2["hasEfka_or_Public_Debt"]))
                            $entry["isDebtor"] = $v2["hasEfka_or_Public_Debt"];
                        else
                            $entry["isDebtor"] = false;

                        $found = true;
                        break;
                    }
                }
            }

            if (! $found && count($dataS) > 0) {
                foreach ($dataS as $k2 => $v2) {
                    if ($v1 == $v2["vat"]) {
                        $entry = $v2;
                        // if (isset($v2["market"]))
                        $entry["marketName"] = $v2["market"];
                        // if (isset($v2["active"]))
                        $entry["isActive"] = $v2["active"];
                        // if (isset($v2["turnOverAmount"]))
                        if (isset($v2["turnOverAmount"]))
                            $entry["turnOverAmount"] = $v2["turnOverAmount"] . "";
                        else
                            $entry["turnOverAmount"] = null;

                        if (isset($v2["turnOverYear"]))
                            $entry["turnOverYear"] = $v2["turnOverYear"];
                        else
                            $entry["turnOverYear"] = null;

                        if (isset($v2["mainCpaName"]) && $v2["mainCpaName"] != null) {
                            $entry["mainCpaName"] = $v2["mainCpaName"];
                        } elseif (isset($v2["topCpaName"]) && $v2["topCpaName"] != null) {
                            $entry["mainCpaName"] = $v2["topCpaName"];
                        }

                        if (isset($v2["gemhNumber"]) && $v2["gemhNumber"] != null) {
                            $entry["gemh"] = $v2["gemhNumber"];
                        }

                        if (isset($v2["ebit"]) && $v2["ebit"] != null) {
                            $entry["earnings"] = $v2["ebit"];
                        }

                        if (isset($v2["chamber"]) && $v2["chamber"] != null) {
                            $entry["office"] = $v2["chamber"];
                        }

                        if (isset($v2["orgType_category"]) && $v2["orgType_category"] != null) {
                            $entry["orgType"] = $v2["orgType_category"];
                        }
                        if (isset($v2["hasEfka_or_Public_Debt"]))
                            $entry["isDebtor"] = $v2["hasEfka_or_Public_Debt"];
                        else
                            $entry["isDebtor"] = false;

                        $found = true;
                        break;
                    }
                }
            }
            array_push($new, $entry);
        }
        return $new;
    }



    /**
     * This function handle the request to retrieve market(purchased) order with the Companies(Results)
     * It accepts a Request with api_token, user_role, user_email , order_id
     * Returns (JSON)(ARRAY) 
     */
    public function MarketOrder(Request $request)
    {
        //Set Helper Controller && error 
        $helperCont = (new WebPortalHelperControllerV1);
        $error = false;
        $results = null;
        //Set Basic Api call headers
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'MarketGet', ['order_id', 'user_email']);
        http_response_code(200);
        //Set Orderid
        $orderId = $request['order_id'];
        Log::info("OrderId is: " . $orderId);
        //Select from DB the given order from the user
        try {
            $ord = DB::select("SELECT * FROM b2bsaleslead_user_to_order WHERE order_id = ? AND user = ? AND status = 'enabled'", [$request["order_id"], $request["user_email"]]);
        } catch (Exception $e) {
            Log::error("Error on B2B Order try to reading call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while reading call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Verify if it found matched order in DB for the user
        if (count($ord) == 0) {
            http_response_code(401);
            echo '{"errors":[{"title":"unauthorized access for <user_email to order_id>","error_code": 5}]}';
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
            exit();
        }
        //Get BearerToken Parameter for the curl
        $token = $this->bearerToken();
        //Set init for multi curls
        $mh = curl_multi_init();
        //Get APP.Enviroment for B2bLiveCounts
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Set Path for S3 Bucket of market stats
        $filepath = config("keycloak.Aws_Folder_B2B_STATS") . env('APP_Env') . '/file.json';
        //Check if has market stats and retrieve
        if (Storage::disk('s3-b2b')->exists($filepath)) {
            //Get Market stats 
            $s3Markets = Storage::disk('s3-b2b')->get($filepath);
            $marketStatsRes = json_decode($s3Markets, true);
        } else {
            //Get Markets Name 
            $curlMarketStats = $this->prepareGetCurl(config("keycloak.MarketStats"), $token, '', 60);
            curl_multi_add_handle($mh, $curlMarketStats);
        }
        //define lang
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }
        //Set Path for S3 Bucket of market order
        $orderFilepath = config("keycloak.Aws_Folder_B2B_ORDERS") . $environmt . '/' . $orderId . '/' . $lang . '.json';

        //Check if has market stats and retrieve
        if (Storage::disk('s3-b2b')->exists($orderFilepath) && isset($marketStatsRes)) {
            //Get Market order            
            $s3MarketOrder = Storage::disk('s3-b2b')->get($orderFilepath);
            $dataCompanies = json_decode($s3MarketOrder, true);
            //Set 
            $dataResource = [
                'lang' => $lang,
                'token' => $token,
                'environmt' => $environmt,
                'lb_markets' => $marketStatsRes["lb_markets"]
            ];
            //Set order info
            $orderData = $helperCont->getMarketOrderInfoFormatted($ord, $dataResource);
            //Set order info to results
            $results['orderInfo'] = $orderData['orderInfo'];
            //Set Order Companies data to response
            $results['Companies'] = $dataCompanies["Companies"];
        } else {
            //Set Curl 
            $q = "?lang=" . $lang . "&order_id=" . $orderId;
            $curlOrder = $this->prepareGetCurl(config("keycloak.SmartFilterOrder"), $token, $q, 60);
            curl_multi_add_handle($mh, $curlOrder);
        }
        if (Cache::get('order-verified-' . env('APP_Env') . '-' . $lang . '-' . $orderId) === 'handled') {
            if (Cache::has('order-verified-data-' . env('APP_Env') . '-' . $lang . '-' . $orderId)) {
                $results = Cache::get('order-verified-data-' . env('APP_Env') . '-' . $lang . '-' . $orderId);
                $results = json_decode($results);
                $curlAsync = curl_init();
                $env = env('APP_Env');
                $post = (object) [
                    'api_token' => $request['api_token'],
                    'lang' => $lang,
                    'orderId' => $orderId,
                    'ord' => json_encode($ord),
                    'env' => $env,
                ];
                curl_setopt_array($curlAsync, array(
                    CURLOPT_URL => config("keycloak.WPD_URL") . '/api/dev/WebPortal/market/verifyOrder',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT_MS => 100,
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => json_encode($post),
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_HTTPHEADER => array(
                        "accept: */*",
                        "Content-Type: application/json",
                    ),
                ));
                $curlRe = curl_exec($curlAsync);
                curl_close($curlAsync);
                echo json_encode($results);

                $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
                exit();
            }
        }
        if (isset($curlOrder) || isset($curlMarketStats)) {
            //Execute curl and check for error/done status
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh);
                }
            } while ($active && $status == CURLM_OK);
            if ($status != CURLM_OK) {
                curl_multi_close($mh);
                //Check if some error has occurred and exit
                $error = true;
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token)) {
                    Log::error('B2B Market Order - Failed to make complete requests for call id:' . $idc1);
                    exit();
                }
            }
            //Handle Curl responses
            if (isset($curlMarketStats)) {
                $rj2t = curl_multi_getcontent($curlMarketStats);
                $marketStatsRes = json_decode($rj2t, true);
                //Set Error if something with MarketStats curl or token happend
                if (! isset($marketStatsRes["lb_markets"]))
                    $error = true;
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token)) {
                    Log::error('B2B Market Order - Failed to get markets for call id: ' . $idc1);
                    exit();
                }
            }
            if (isset($curlOrder)) {
                $rj1t = curl_multi_getcontent($curlOrder);
                //Catch case for Service response "Error while trying to recieve Value of key stag_100 in Redis" with STATUS 200
                if (strpos($rj1t, 'Companies') === false)
                    $error = true;
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token)) {
                    Log::error('B2B Market Order - Failed to get order with id: ' . $orderId);
                    exit();
                }
                $rj1t = str_replace('"companies":', '"Companies":', $rj1t);
                $dataCompanies = json_decode($rj1t, true);
                //Set data Resource for detail info
                $dataResource = [
                    'lang' => $lang,
                    'token' => $token,
                    'environmt' => $environmt,
                    'lb_markets' => $marketStatsRes["lb_markets"]
                ];
                //Set order info
                $orderData = $helperCont->getMarketOrderInfoFormatted($ord, $dataResource);
                //Set order info to results
                $results['orderInfo'] = $orderData['orderInfo'];
                //Set Order Companies data to response
                $results['Companies'] = $dataCompanies["Companies"];
            }
        }
        if (Cache::get('order-verified-' . env('APP_Env') . '-' . $lang . '-' . $orderId) !== 'verified') {
            $isValid = $helperCont->isB2BValid($results);
            if (! $isValid) {
                Cache::put('order-verified-data-' . env('APP_Env') . '-' . $lang . '-' . $orderId, json_encode($results), 43800);
                Cache::put('order-verified-' . env('APP_Env') . '-' . $lang . '-' . $orderId, 'handled', 43800);
            } else {
                Cache::put('order-verified-' . env('APP_Env') . '-' . $lang . '-' . $orderId, 'verified', 43800);
            }
        }
        //Return results
        echo json_encode($results);

        $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
        exit();
    }

    public function verifyOrderAsync(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        $lang = $request->lang;
        $token = $this->bearerToken();
        $orderId = $request->orderId;
        $ord = json_decode($request->ord);
        $env = $request->env;
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        //Set Curl 
        $mh = curl_multi_init();
        //Get Markets Name 
        $curlMarketStats = $this->prepareGetCurl(config("keycloak.MarketStats"), $token, '', 60);
        curl_multi_add_handle($mh, $curlMarketStats);
        $q = "?lang=" . $lang . "&order_id=" . $orderId;
        $curlOrder = $this->prepareGetCurl(config("keycloak.SmartFilterOrder"), $token, $q, 60);
        curl_multi_add_handle($mh, $curlOrder);
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);
        if ($status != CURLM_OK) {
            curl_multi_close($mh);
            //Check if some error has occurred and exit
            $error = true;
            Log::error('B2B Market Order - Failed to make complete requests for call id:');
            exit();
        }
        //Handle Curl responses
        $rj2t = curl_multi_getcontent($curlMarketStats);
        $marketStatsRes = json_decode($rj2t, true);
        //Set Error if something with MarketStats curl or token happend
        if (! isset($marketStatsRes["lb_markets"])) {
            $error = true;
        }
        $rj1t = curl_multi_getcontent($curlOrder);
        //Catch case for Service response "Error while trying to recieve Value of key stag_100 in Redis" with STATUS 200
        if (strpos($rj1t, 'Companies') === false) {
            $error = true;
        }
        $rj1t = str_replace('"companies":', '"Companies":', $rj1t);
        $dataCompanies = json_decode($rj1t, true);
        //Set data Resource for detail info
        $dataResource = [
            'lang' => $lang,
            'token' => $token,
            'environmt' => $env,
            'lb_markets' => $marketStatsRes["lb_markets"]
        ];
        //Set order info
        $orderData = $helperCont->getMarketOrderInfoFormatted($ord, $dataResource);
        //Set order info to results
        $results['orderInfo'] = $orderData['orderInfo'];
        //Set Order Companies data to response
        $results['Companies'] = $dataCompanies["Companies"];

        $isValid = $helperCont->isB2BValid($results);
        if (! $isValid) {
            Cache::put('order-verified-data-' . $env . '-' . $lang . '-' . $orderId, json_encode($results), 43800);
            Cache::put('order-verified-' . $env . '-' . $lang . '-' . $orderId, 'handled', 43800);
        } else {
            Cache::put('order-verified-' . $env . '-' . $lang . '-' . $orderId, 'verified', 43800);
        }
        return json_encode($results);
    }
    /**
     * This function handle the request to search with given filters and return basic stats and companies. Also to register a market purchase.
     * It accepts a Request with api_token, user_role, user_email , different filters
     * Returns (JSON)(ARRAY) 
     */
    public function MarketSearch(Request $request, $d = null)
    {
        //Set Helper Controller && error 
        $helperCont = (new WebPortalHelperControllerV1);
        $error = false;
        //Set For cached enviroment
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Set Basic Api call headers
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'MarketGet', ['market_id']);
        http_response_code(200);

        //set lang
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }

        //Set Basic(Required) Query String/Parameter 
        $q = "?market=" . $request["market_id"];
        //Check Required Param order_id,type for /purchase 
        if ($d == "purchase") {
            //Validate Request params
            if (! $helperCont->validateRequestParams($request, ['type', 'order_id'], 'b2bsaleslist_calls', $idc1))
                exit();
            if ($request['type'] !== "Universe" && $request['type'] !== "Live") {
                http_response_code(400);
                echo '{"errors":[{"title":"format not valid for <type>","error_code": 9}]}';
                //Check & Update Db table record that has pass this stage. 
                $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

                exit();
            }
        }
        //Add to Query any filter that is given 
        $filterList = [
            'chamber',
            'postal_code',
            'region',
            'regionUnit',
            'orgtype',
            'results',
        ];
        $q .= $helperCont->addFiltersToQueryString($request, $filterList, $idc1);
        $q .= $helperCont->addDateFiltersToQueryString($request);
        //Set dates for q
        $df = isset($request['date_from']) ? $request['date_from'] : '1900-01-01';
        $dt = isset($request['date_to']) ? $request['date_to'] : date("Y-m-d");
        //Get Bearer Auth token
        $token = $this->bearerToken();
        //Finish last basic query param
        $q .= "&values=true";
        //Get Market Search count  only case
        if (isset($request['max_companies']) && $d != "purchase") {
            $q .= "&max=" . $request['max_companies'] . "&lang=" . $lang;
            //Get Count from Cases if exist
            if (Cache::has("SmartFilterCounts" . $q . $df . $dt . "_" . $environmt)) {
                $resp3c = Cache::get("SmartFilterCounts" . $q . $df . $dt . "_" . $environmt);
            } else {
                //Prepare Curl for Count Service
                $curl3c = $this->prepareGetCurl(config("keycloak.SmartFilterCounts"), $token, $q, 180);
                //Get Count from Service
                $res = curl_exec($curl3c);
                $err = curl_error($curl3c);
                curl_close($curl3c);
                //Set Error if something went wrong
                if ($err || str_contains($res, "Internal Server Error"))
                    $error = true;
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token))
                    exit();
                //Prepare the Response
                $rest = json_decode($res, true);
                $resp3c = json_encode(
                    array(
                        "count_total" => isset($rest["count_total"]) ? $rest["count_total"] : 0,
                        "count_month" => array(
                            "month" => isset($rest["last_month"]) ? intval($rest["last_month"]) : 0,
                            "count" => isset($rest["count_m1"]) ? $rest["count_m1"] : 0
                        ),
                        "count_month3" => isset($rest["count_m3"]) ? $rest["count_m3"] : 0,
                        "count_month12" => isset($rest["count_m12"]) ? $rest["count_m12"] : 0,
                        "Companies" => isset($rest["sample"]) ? $rest["sample"] : []
                    )
                );
                //Store to cached market stat response
                Cache::put("SmartFilterCounts" . $q . $df . $dt . "_" . $environmt, $resp3c, config("keycloak.SmartFilterCacheTime"));
            }
            //Return the response
            echo $resp3c;
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

            exit();
        }

        $mh = curl_multi_init();
        $qres = "";
        if (isset($request['results_low']) || isset($request['results_top'])) {
            // $qres .= "?";
            if (isset($request['results_low'])) {
                $qres = "?amount_low=" . $request['results_low'] . "&";
            } else {
                $qres = "?amount_low=0&";
            }
            if (isset($request['results_top'])) {
                $qres .= "amount_top=" . $request['results_top'];
            } else {
                $qres .= "amount_top=1000000000000000";
            }
            //Get search from cached if exist
            if (Cache::has("SmartFilterRes" . $qres . "_" . $environmt)) {
                $resp2 = Cache::get("SmartFilterRes" . $qres . "_" . $environmt);
            } else {                
                $curl2 = $this->preparePostCurl(config("keycloak.SmartFilterResults") . $qres, $token, '', 180);
                curl_multi_add_handle($mh, $curl2);
            }
        }

        if (Cache::has("SmartFilter" . $q . $df . $dt . "_" . $environmt)) {
            $resp3 = Cache::get("SmartFilter" . $q . $df . $dt . "_" . $environmt);
        } else {
            $curl3 = $this->prepareGetCurl(config("keycloak.SmartFilter"), $token, $q, 180);
            curl_multi_add_handle($mh, $curl3);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);
        //Chech curls if went ok
        if ($status != CURLM_OK) {
            curl_multi_close($mh);
            $error = true;
            //Check if some error has occurred and exit
            if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error))
                exit();
        }

        if (isset($request['results_low']) || isset($request['results_top'])) {
            if (isset($curl3)) {
                $rj3t = curl_multi_getcontent($curl3);
                Cache::put("SmartFilter" . $q . $df . $dt . "_" . $environmt, $rj3t, config("keycloak.SmartFilterCacheTime"));
                curl_multi_remove_handle($mh, $curl3);
            } else {
                $rj3t = $resp3;
            }

            $art = json_decode($rj3t, true);
            $ar = array();
            $ar["count_total"] = $art["count_total"];
            $ar["count_month"] = $art["count_month"];
            $ar["Companies"] = array();

            if (isset($curl2)) {
                $rj2t = curl_multi_getcontent($curl2);
                Cache::put("SmartFilterRes" . $qres . $df . $dt . "_" . $environmt, $rj2t, config("keycloak.SmartFilterCacheTimeResults"));
                curl_multi_remove_handle($mh, $curl2);
            } else {
                $rj2t = $resp2;
            }
            $rj2 = json_decode($rj2t, true)["data"]["Companies"];

            if (isset($art["Companies"])) {
                $i = 0;
                foreach ($art["Companies"] as $k => $v) {
                    if (in_array($v["vat"], $rj2)) {
                        $ar["Companies"][$i] = $v;
                        $i++;
                    }
                }
                $ar["count_total"] = $i;
                $ar["count_month"]["count"] = 0;
            }

        } else {
            if (isset($curl3)) {
                $rj3t = curl_multi_getcontent($curl3);
                Cache::put("SmartFilter" . $q . $df . $dt . "_" . $environmt, $rj3t, config("keycloak.SmartFilterCacheTime"));
                curl_multi_remove_handle($mh, $curl3);
            } else {
                $rj3t = $resp3;
            }
            $ar = json_decode($rj3t, true);
            if (isset($ar["sample"]))
                unset($ar["sample"]);
        }

        curl_multi_close($mh);

        if ($d == null) {
            $res = json_encode($ar);
            //Return repsonse
            echo $res;
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

            exit();
        } elseif ($d == "purchase") {
            //Get order from db
            try {
                $ex = DB::table('b2bsaleslead_user_to_order')->where("order_id", $request["order_id"])->count();
            } catch (Exception $e) {
                http_response_code(500);
                echo "{'errors':[{'title':'Internal Server Error while reading call to WPD DB','error_code': '10'}]}";
                exit();
            }
            //Check if order id is registered
            if ($ex > 0) {
                $r = null;
                $r["messages"][0]["title"] = "the purchase with order_id:" . $request["order_id"] . " is already registered";
                $r["messages"][0]["message_code"] = 3;
                http_response_code(409);
                echo json_encode($r);

                $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
                exit();
            }
            //Create record for order in DB 
            try {
                DB::table('b2bsaleslead_user_to_order')
                    ->insert(array(
                        'user' => $request["user_email"],
                        'order_id' => $request["order_id"],
                        'type' => $request["type"],
                        'filters' => $q . $qres,
                        'date' => date("Y-m-d H:i:s"),
                        'status' => 'enabled',
                        'market_id' => $request["market_id"]
                    )
                    );
            } catch (Exception $e) {
                http_response_code(500);
                Log::error('B2B Market Order - Failed to insert purchased order with id:' . $request["order_id"] . ' for user: ' . $request["user_email"] . ' and market-type: ' . $request["market_id"] . ' ' . $request["type"]);
                echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
                exit();
            }
            if ($request["type"] == "Universe") {
                // $dbar = array();
                // foreach ($ar["Companies"] as $k => $v) {
                //     array_push($dbar, array('order_id' => $request["order_id"], 'vat' => $v["vat"]));
                // }
                $qUni = $q . "&order_id=" . $request["order_id"];
                $curlUniversePurchase = $this->prepareGetCurl(config("keycloak.SmartFilterUniversePurchase"), $token, $qUni, 50);
                $resp = curl_exec($curlUniversePurchase);
                $err = curl_error($curlUniversePurchase);
                curl_close($curlUniversePurchase);
                //Set error
                if ($err)
                    $error = true;
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token))
                    exit();
                // try {
                //     DB::table('b2bsaleslead_order_to_company_universe')->insert($dbar);
                // } catch (Exception $e) {
                //     http_response_code(500);
                //     echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
                //     exit();
                // }

            } elseif ($request["type"] == "Live") {
                $qLive = "?order_id=" . $request["order_id"] . "&market=" . $request["market_id"];
                $curlLivePurchase = $this->prepareGetCurl(config("keycloak.SmartFilterLivePurchase"), $token, $qLive, 50);
                $resp = curl_exec($curlLivePurchase);
                $err = curl_error($curlLivePurchase);
                curl_close($curlLivePurchase);
                //Set error
                if ($err)
                    $error = true;
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token))
                    exit();
            }

            $r = null;
            $r["messages"][0]["title"] = "the purchase has been registered";
            $r["messages"][0]["message_code"] = 1;

            http_response_code(200);
            echo json_encode($r);
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
            exit();
        }
    }
    /**
     * This function handle the request to retrieve market stats for market search autocomplete fill out.
     * It accepts a Request with api_token, user_role, user_email
     * Returns (JSON)(ARRAY) with market's search autocomplete stats
     */
    public function MarketStats(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Markets Restriction for V2-V3 by market_id
        //$markfilt = array("1110", 1109, 1102, 1112, 1113, 1101, 1096, 1097, 1093, 1092, 1095, 1151, 1090, 1089, 1091, 1098, 1119, 1079, 1100, 1120, 1107, 1103, 1124, 1104, 1099, 1121, 1122, 1473, 1478, 1529, 1530, 1531, 1532, 1533, 1534, 1535, 1536, 1537, 1538, 1539, 1540, 1541, 1542, 1543, 1544, 1545, 1546, 1547, 1548, 1549, 1550, 1552, 1553, 1554, 1555, 1556, 1557, 1558, 1559, 1560, 1561, 1562, 1563, 1596);
        $results = array();
        $lang = $request->lang;
        //Set Basic Api call headers
        $error = false;
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'MarketStats', null);
        //Set Path for S3 Bucket
        $filepath = config("keycloak.Aws_Folder_B2B_STATS") . env('APP_Env') . '/file.json';
        // Check if has market stats 
        if (Storage::disk('s3-b2b')->exists($filepath)) {
            //Get Market stats 

            $marketStats = json_decode(Storage::disk('s3-b2b')->get($filepath), true);

            //Get Formatted Response
            try {
                $results['markets'] = $helperCont->getMarketStatsFormattedResponse($lang, $marketStats["lb_markets"], null);
            } catch (Exception $e) {
                $error = true;
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error)) {
                    exit();
                }
                // Nothing, yet
            }
            $results = $this->addValuesToMarketStats($results);
            echo json_encode($results);
            //Update DB call status record
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', true, null);
            exit();
        }
        //Get Bearer Token
        $token = $this->bearerToken("gsis-api");
        //Prepare curl request
        $curlTimeout = 120;
        $curlQuery = '';
        $curl = $this->prepareGetCurl(config("keycloak.MarketStats"), $token, $curlQuery, $curlTimeout);
        //Execute Curl
        $resp = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        //Decode to json Curl Response
        $mes = json_decode($resp, true);
        //Set Error from curl err or response 
        if ($err || ! isset($mes["lb_markets"])) {
            $error = true;
        }
        //Check if some error has occurred and exit
        if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error, $token))
            exit();

        //Get Formatted Response
        try {
            $results['markets'] = $helperCont->getMarketStatsFormattedResponse($lang, $mes["lb_markets"], $markfilt);
        } catch (Exception $e) {
            $error = true;
            //Check if some error has occurred and exit
            if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $error))
                exit();
            // Nothing, yet
        }
        //Return method's response
        $results = $this->addValuesToMarketStats($results);
        $results = json_encode($results);
        echo $results;
        $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
        exit();
    }



    public function addValuesToMarketStats($mark)
    {
        if (! isset($mark["markets"])) {
            return $mark;
        }
        foreach ($mark["markets"] as $k => $v) {
            if (! isset($mark["markets"][$k]["cpaCount"])) {
                $mark["markets"][$k]["cpaCount"] = 7;
            }
            $mark["markets"][$k]["category_descr"] = "CPA_" . str_replace(" ", "", strtoupper($v["category_en"]));
            $mark["markets"][$k]["segment_descr"] = "SEG_" . str_replace(" ", "", strtoupper($v["segment_en"]));
        }
        return $mark;
    }
    /**
     * The method accepts a Request
     * This method is responsible for creating, updating and running all operations responsible for creating a new order inside BackOffice
     * The method can (if applicable) return directly a pre-sanitized PDF version of the vat being asked from the S3 storage
     * Returns (JSON) Error/ Success message
     */
    public function ReportSubmit(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        header('Content-type: application/json');

        //General variables
        $api_token = $request->only('api_token')["api_token"];
        $vat_number_list = $request["vat_list"];
        $local_application_code = date("YmdHis") . substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6);
        $current_date = date("Y-m-d H:i:s");

        //Successful response message array
        $successful_registration_response["applicationCode"] = $local_application_code;
        $successful_registration_response["messages"][0]["title"] = "the application has been registered";
        $successful_registration_response["messages"][0]["message_code"] = 1;

        //Flag variables for regulating controller behaviour with S3 
        $bypass_s3 = false;
        $s3_exists = true;

        //Check if S3 checking must be skipped. This will create new order inside BackOffice if provided & true
        if (isset($request["bypass_S3"])) {
            if ($request["bypass_S3"]) {
                $bypass_s3 = true;
            }
        }

        //Flag variable for regulating controller behavour with file production
        $bypass_file_production = false;

        //Check if file production must be skipped. This will create new order inside BackOffice if provided & true without file
        if (isset($request["bypass_file_production"])) {
            if ($request["bypass_file_production"]) {
                $bypass_file_production = true;
            }
        }

        //Create DB record for call in DB
        try {
            $idc1 = DB::table('pdf_report_calls')
                ->insertGetId(
                    array(
                        'date' => $current_date,
                        'service' => 'ReportSubmit',
                        'request' => json_encode($request->all()),
                        'completed' => false
                    )
                );
        } catch (Exception $e) {
            Log::error("Error on PDF Report Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }

        //Operations to check if resquest is valid
        //Check API_TOKEN existence
        if (! isset($request["api_token"])) {
            http_response_code(401);
            echo '{"errors":[{"title":"api token is not valid","error_code": 0}]}';

            DB::table('pdf_report_calls')
                ->where('id', $idc1)
                ->update(
                    array(
                        'completed' => true
                    )
                );


            exit();
        }

        //Fetch user information for cross validation of api token provided
        try {
            $user = DB::select("SELECT * FROM users WHERE api_token = :api_token", ['api_token' => $api_token]);
        } catch (Exception $e) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while fetching user from DB','error_code': '10'}]}";
            exit();
        }

        //User token is incorrect handling
        if (count($user) == 0) {
            http_response_code(401);
            echo '{"errors":[{"title":"api token is not valid","error_code": 0}]}';

            DB::table('pdf_report_calls')
                ->where('id', $idc1)
                ->update(
                    array(
                        'completed' => true
                    )
                );

            exit();
        }

        //Check VAT_LIST existence
        if (! isset($request["vat_list"])) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <vat> is missing ","error_code": 6}]}';

            DB::table('pdf_report_calls')
                ->where('id', $idc1)
                ->update(
                    array(
                        'completed' => true
                    )
                );

            exit();
        }

        //Check PACKAGE_NAME existence
        if (! isset($request["package_name"])) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <package_name> is missing ","error_code": 6}]}';

            DB::table('pdf_report_calls')
                ->where('id', $idc1)
                ->update(
                    array(
                        'completed' => true
                    )
                );

            exit();
        }

        //Health case for API testing
        if (! strcmp($request["package_name"], "Test")) {
            http_response_code(200);
            exit();
        }

        //Create DB record for the new order
        try {
            $idc2 = DB::table('pdf_report_orders')
                ->insertGetId(
                    array(
                        'applicationCode' => $local_application_code,
                        'date' => $current_date,
                        'duedate' => $this->duedateforpdf($request["package_name"]),
                        'status' => 'Running',
                        'user' => $request['user_email']
                    )
                );
        } catch (Exception $e) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while creating DB record for Order','error_code': '10'}]}";
            exit();
        }

        //Operation to create DB record for each vat asked in request
        foreach ($vat_number_list as $vat_number) {
            //If bypass_s3 flag is not true check if report already exists in S3
            if (! $bypass_s3 && ! $bypass_file_production) {
                if ($this->existsReportInS3($vat_number, "BR")) { //S3 record for vat exist
                    $pdf_link_s3 = $this->getS3Report($vat_number, $local_application_code);
                    try {
                        $idc3 = DB::table('pdf_report_vats')
                            ->insertGetId(
                                array(
                                    'order_applicationCode' => $local_application_code,
                                    'vat_applicationCode' => 'S3',
                                    'date' => $current_date,
                                    'vat' => $vat_number,
                                    'type' => $request["package_name"],
                                    'status' => 'Completed',
                                    'link' => $pdf_link_s3
                                )
                            );
                    } catch (Exception $e) {
                        http_response_code(500);
                        echo "{'errors':[{'title':'Internal Server Error while creating DB record for Vat','error_code': '10'}]}";
                        exit();
                    }
                } else { //S3 record for vat does NOT exist
                    $s3_exists = false;
                    try {
                        $idc3 = DB::table('pdf_report_vats')
                            ->insertGetId(
                                array(
                                    'order_applicationCode' => $local_application_code,
                                    'date' => $current_date,
                                    'vat' => $vat_number,
                                    'type' => $request["package_name"],
                                    'status' => 'Running'
                                )
                            );
                    } catch (Exception $e) {
                        http_response_code(500);
                        echo "{'errors':[{'title':'Internal Server Error while creating DB record for Vat','error_code': '10'}]}";
                        exit();
                    }
                }
            } else { //Bypass S3 checking, handle request as a new order
                $s3_exists = false;

                if ($bypass_file_production && ! $bypass_s3) {
                    try {
                        DB::table('pdf_report_vats')
                            ->insertGetId(
                                array(
                                    'order_applicationCode' => $local_application_code,
                                    'vat_applicationCode' => 'no-file',
                                    'date' => $current_date,
                                    'vat' => $vat_number,
                                    'type' => $request["package_name"],
                                    'status' => 'Completed',
                                    'link' => 'no-file'
                                )
                            );
                    } catch (Exception $e) {
                        http_response_code(500);
                        echo "{'errors':[{'title':'Internal Server Error while creating DB record for Vat','error_code': '10'}]}";
                        exit();
                    }
                } elseif (! $bypass_file_production && $bypass_s3) {
                    try {
                        $idc3 = DB::table('pdf_report_vats')
                            ->insertGetId(
                                array(
                                    'order_applicationCode' => $local_application_code,
                                    'date' => $current_date,
                                    'vat' => $vat_number,
                                    'type' => $request["package_name"],
                                    'status' => 'Running'
                                )
                            );
                    } catch (Exception $e) {
                        http_response_code(500);
                        echo "{'errors':[{'title':'Internal Server Error while creating DB record for Vat','error_code': '10'}]}";
                        exit();
                    }
                } else {
                    http_response_code(500);
                    echo "{'errors':[{'title':'bypass_s3 and bypass_file_production can not have the same value','error_code': '10'}]}";
                    exit();
                }
            }
        }

        //Inform request service for the successful registration of request
        http_response_code(200);
        echo json_encode($successful_registration_response);

        //If S3 document exists send completion notification to users email
        if ($s3_exists) {
            $helperCont->setCallCompleted($idc1, 'pdf_report_calls', false, null);
        } else { //If S3 document does not exist start new order operations
            $this->triggerCheckPendingPdfReports();
        }

    }

    public function duedateforpdf($report = 'Advanced')
    {
        $dt = date('Y-m-d H:i:s');
        return date('Y-m-d H:i:s', strtotime($dt . ' + 4 days'));
    }

    public function ReportGet(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);

        $idc1 = $helperCont->initializeRequestHelper($request, 'pdf_report_calls', 'ReportGet', null);
        $helperCont->checkIfSet($request, $idc1, ['applicationCode']);
        $ac = $request["applicationCode"];
        $order = DB::table('pdf_report_orders')->where('applicationCode', $ac)->get();

        if (count($order) == 0) {

            $r["messages"][0]["title"] = "application not found";
            $r["messages"][0]["message_code"] = 7;
            $htc = "404";
            http_response_code($htc);
            echo json_encode($r);
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        } elseif (reset($order)[0]->status != "Completed") {

            $r["applicationCode"] = $ac;
            $r["status"] = "Running";
            $r["file"] = null;
            http_response_code(200);
            echo json_encode($r);
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        } else {
            $zip = new ZipArchive();

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $rootPath = storage_path() . '\BR\\' . $ac;
                $zip->open($rootPath . '\\' . $ac . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
            } else {
                $rootPath = storage_path() . '/BR/' . $ac;
                $zip->open($rootPath . '/' . $ac . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
            }

            if (! is_dir($rootPath)) {
                $mypath = $rootPath;
                mkdir($rootPath, 0755, TRUE);
            }

            $tasks = DB::table('pdf_report_vats')->where('order_applicationCode', $ac)->where('vat_applicationCode', 'S3')->get();

            if (count($tasks) == 0) {
                $r["applicationCode"] = $ac;
                $r["status"] = "Running";
                $r["file"] = null;
                http_response_code(200);
                echo json_encode($r);
                DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
                exit();
            }

            foreach ($tasks as $task) {
                $fileUrl = $task->link;
                $flavor = $task->type;
                $vat = $task->vat;

                $date = $task->date;
                $date = explode(" ", $date);
                $date = explode("-", $date[0]);

                $fileName = "lb-report-" . $flavor . "-" . $date[0] . $date[1] . "-" . $vat . ".pdf";

                if (! $this->existsReportInS3($ac, "WPD-BR")) {
                    if (! $this->existsReportInS3($vat, "BR")) {
                        $businessReportApplicationCode = $task->vat_applicationCode;
                        $fileUrl = $this->getBrReportFromService($vat, $businessReportApplicationCode, $ac);
                    } else {
                        $fileUrl = $this->getS3Report($vat, $ac);
                    }
                }
                //Get Report From WDP Bucket
                $reportFile = Storage::disk('s3-wpd')->get($fileUrl);

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    file_put_contents($rootPath . '\\' . $fileName, $reportFile);
                    $newFileUrl = $rootPath . '\\' . $fileName;
                } else {
                    file_put_contents($rootPath . '/' . $fileName, $reportFile);
                    $newFileUrl = $rootPath . '/' . $fileName;
                }

                $relativePath = substr($newFileUrl, strlen($rootPath) + 1);
                $zip->addFile($newFileUrl, $relativePath);
            }

            $zip->close();
            //Delete Local report file 
            unlink($newFileUrl);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                $fileZipPath = $rootPath . '\\' . $ac . '.zip';
            else
                $fileZipPath = $rootPath . '/' . $ac . '.zip';

            $file = file_get_contents($fileZipPath);
            $file64 = base64_encode($file);

            $r["applicationCode"] = $ac;
            $r["status"] = "Completed";
            $r["file"]["mime"] = "application/zip;base64";
            $r["file"]["data"] = $file64;


            http_response_code(200);
            echo json_encode($r);
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));

            //Delete Local zip file 
            unlink($fileZipPath);
        }
    }

    public function getBrReportFromService($vat, $businessReportApplicationCode, $orderApplicationCode)
    {
        $token = $this->bearerToken();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pathr = storage_path() . '\BR';
        } else {
            $pathr = storage_path() . '/BR';
        }

        if (! is_dir($pathr)) {
            $mypath = $pathr;
            mkdir($mypath, 0755, TRUE);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $path = $pathr . "\\" . $orderApplicationCode;
        } else {
            $path = $pathr . "/" . $orderApplicationCode;
        }

        if (! is_dir($path)) {
            $mypath = $path;
            mkdir($mypath, 0755, TRUE);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $filelink = $path . '\\' . $vat . '-' . $businessReportApplicationCode . '.pdf';
        } else {
            $filelink = $path . '/' . $vat . '-' . $businessReportApplicationCode . '.pdf';
        }

        $fp = fopen($filelink, 'w+');
        if ($fp === false) {
            throw new Exception('Could not open: ' . $path . '\\' . $vat . '-' . $businessReportApplicationCode . '.pdf');
        }

        $post = null;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => config("keycloak.BR_API_DPDF") . $businessReportApplicationCode,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HEADER => 1,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => array(
                "accept: */*",
                "Content-Type: application/json",
                "Authorization: Bearer " . $token
            ),
        ));

        $businessReportServiceResponse = curl_exec($ch);
        $err = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($err) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while fetching BR PDF File','error_code': '10'}]}";
            abort(500);
        }

        //If ticket ID does not exist throw error
        if ($httpcode != 200) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while fetching Business Report File from BR Service': '10'}]}";
            exit();
        }

        fclose($fp);



        $fileName = $orderApplicationCode . "/" . $vat . '.pdf';
        $link = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
        //Save it to WPD S3 bucket
        Storage::disk('s3-wpd')->put($link, file_get_contents($filelink));
        //Clean local report file
        unlink($filelink);

        return $link;
    }

    public function checkAllPendingVats($onlyid = null) //if onlyid not null will get new report, updating the applicationCode
    {
        date_default_timezone_set('Europe/Athens');
        if (! Cache::has("pendingpdforders")) {
            return true;
        }

        if ($onlyid == null) {
            $pendingvats = DB::table('pdf_report_vats')->where('status', '<>', "Completed")->get();
        } else {
            $pendingvats = DB::table('pdf_report_vats')->where('id', '=', $onlyid)->get();
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pathr = storage_path() . '\BR';
        } else {
            $pathr = storage_path() . '/BR';
        }
        if (! is_dir($pathr)) {
            $mypath = $pathr;
            mkdir($mypath, 0755, TRUE);
        }

        foreach ($pendingvats as $k => $v) {
            Cache::put("pdf-whatisrunningbackground", "BR for vat: " . $v->vat . " and applicationCode: " . $v->vat_applicationCode, 5);
            sleep(3);
            $now = new DateTime(date("Y-m-d H:i:s"));
            $your_date = new DateTime($v->status_update_date);
            $datediff = $now->diff($your_date);
            $minutes = intval($datediff->format('%i'));
            $hours = intval($datediff->format('%h'));
            $ac = $v->vat_applicationCode;
            $va = $v->vat;
            $oac = $v->order_applicationCode;

            $order = DB::table('pdf_report_orders')->where('applicationCode', $oac)->get();
            if (count($order) == 0) { //cleanup vats without order
                DB::table('pdf_report_vats')->where('order_applicationCode', $oac)->delete();
            } elseif ($minutes >= 3 || $onlyid != null || $ac == null) {

                $token = $this->bearerToken();

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $path = $pathr . "\\" . $oac;
                } else {
                    $path = $pathr . "/" . $oac;
                }

                if (! is_dir($path)) {
                    $mypath = $path;
                    mkdir($mypath, 0755, TRUE);
                }

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $filelink = $path . '\\' . $va . '-' . $ac . '.pdf';
                } else {
                    $filelink = $path . '/' . $va . '-' . $ac . '.pdf';
                }
                $fp = fopen($filelink, 'w+');

                if ($fp === false) {
                    throw new Exception('Could not open: ' . $path . '\\' . $va . '-' . $ac . '.pdf');
                }
                $post = null;
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_URL => config("keycloak.BR_API_DPDF") . $ac,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_HEADER => 1,
                    CURLOPT_POSTFIELDS => $post,
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => array(
                        "accept: */*",
                        "Content-Type: application/json",
                        "Authorization: Bearer " . $token
                    ),
                ));

                $tt = curl_exec($ch);

                curl_close($ch);
                fclose($fp);

                $file = file_get_contents($filelink);

                if (str_contains($file, "Content-Type: application/json") || $onlyid != null || filesize($filelink) < 100000 || $ac == null) {

                    if (file_exists($filelink)) {
                        unlink($filelink);
                    }

                    if ($ac == null) {
                        DB::table('pdf_report_vats')->where('vat_applicationCode', $ac)->where('order_applicationCode', $oac)->where('vat', $va)->update(array('status' => 'Running', 'status_update_date' => date("Y-m-d H:i:s"), 'link' => null));
                        $post = '{
                            "vat": "' . $va . '",
                            "language": "gr",
                            "client": {
                            "vat": "string",
                            "name": "string",
                            "subscription": {
                                "name": "string",
                                "template": "string"
                            }
                            }
                        }';                        
                        $curl = $this->preparePostCurl(config("keycloak.BR_API_SUBMIT"), $token, $post, 20);
                        $resp2 = curl_exec($curl);
                        $err2 = curl_error($curl);
                        curl_close($curl);

                        if (! $err2) {
                            $resp3 = json_decode($resp2, true);
                            if (isset($resp3["application_code"])) {
                                DB::table('pdf_report_vats')->where('vat_applicationCode', $ac)->where('order_applicationCode', $oac)->where('vat', $va)->update(array('vat_applicationCode' => $resp3["application_code"], 'status' => 'Running', 'status_update_date' => date("Y-m-d H:i:s"), 'link' => null));
                            }
                        }
                    }
                } else {
                    if (file_exists($filelink)) {
                        $stvat = 'Completed';
                    } else {
                        $stvat = 'Running';
                    }
                    DB::table('pdf_report_vats')->where('vat_applicationCode', $ac)->update(array('status' => $stvat, 'status_update_date' => date("Y-m-d H:i:s"), 'link' => $filelink));

                }

            }
            Cache::forget("pdf-whatisrunningbackground");
        }
        return true;
    }
    public function deletepdforder($id = null)
    {
        if ($id != null) {
            $orders = DB::table('pdf_report_orders')->where('id', $id)->get();
            $aoc = reset($orders)[0]->applicationCode;
            DB::table('pdf_report_orders')->where('id', $id)->delete();
            $vats = DB::table('pdf_report_vats')->where('order_applicationCode', $aoc)->get();
            DB::table('pdf_report_vats')->where('order_applicationCode', $aoc)->delete();
            return "deleted: " . count($orders) . " orders and " . count($vats) . " vat report requests";

        }
        if ($id == 0) {
            DB::table('pdf_report_orders')->truncate();
            DB::table('pdf_report_vats')->truncate();
            DB::table('pdf_report_calls')->truncate();
            DB::table('pdf_report_backoffice_orders')->truncate();
            DB::table('pdf_report_backoffice_tickets')->truncate();
            DB::table('pdf_report_backoffice_tasks')->truncate();
            return "all";
        }
    }

    public function pdfreportHQ($page = null, $num = null)
    {
        if ($page == null) {

            $user = Sentinel::getUser()->id;
            return view('admin.pdfreportHQ', compact('user'));
        }
    }

    public function getDownloadPdf($v = null, $c = null, $co = null)
    {
        if ($v != null && $c != null && $co != null) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $fileName = $co . "\\" . $v . '.pdf';
            } else {
                $fileName = $co . "/" . $v . '.pdf';
            }
            $link = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;

            $headers = array('Content-Type: application/pdf');

            return Storage::disk('s3-wpd')->download($link, $v . "-" . $c . '.pdf', $headers);
        }
    }

    public function getViewPdf($v = null, $c = null, $co = null)
    {
        if ($v != null && $c != null && $co != null) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $file = storage_path() . '\BR\\' . $co . "\\" . $v . "-" . $c . ".pdf";
            } else {
                $file = storage_path() . '/BR/' . $co . "/" . $v . "-" . $c . ".pdf";
            }
            $filename = $v . ".pdf";
            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($file));
            header('Accept-Ranges: bytes');

            @readfile($file);
        }
    }


    public function WhatisrunningB2BList()
    {
        if (Cache::has("whatisrunningbackgroundB")) {
            return Cache::get("whatisrunningbackgroundB");
        } else {
            return "nothing";
        }
    }

    public function pdfWhatisrunning()
    {
        if (Cache::has("pdf-whatisrunningbackground")) {
            return Cache::get("pdf-whatisrunningbackground");
        } else {
            return "nothing";
        }
    }

    public function triggerCheckPendingPdfReports()
    {
        Cache::put("pendingpdforders", "check", 30);
        $helperCont = (new WebPortalHelperControllerV1);
        $response2 = $helperCont->runProcsCurl();
        return $response2;
    }

    public function stopCheckPendingPdfReports()
    {
        Cache::forget("pendingpdforders");
        return 1;
    }
    /**
     * This Function returns trigger any pending B2B Sales Lead List
     * Return JSON(BOOLEAN) any
     */
    public function triggerCheckPendingB2BList()
    {
        //Set Cache to check all pending orders
        Cache::put("pendingpdforders_B2Blistn", 0, 30);
        //Trigger runProcs
        $helperCont = (new WebPortalHelperControllerV1);
        $response2 = $helperCont->runProcsCurl();
        return $response2;
    }

    public function stopCheckPendingB2BList()
    {
        Cache::forget("pendingpdforders_B2Blistn");
        return 1;
    }
    /**
     * This Function handles the request for b2b sales lead list application.
     * It Accepts $request(Request) with requires params :user_email, api_token, user_role, market_id.Also optional: MarketSearch filter as params 
     * Returns JSON with application_code and message
     */
    public function MarketListSubmit(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'B2BSalesListSubmit', ['market_id']);
        //Set random app_code string
        $randapcode = date("YmdHis") . substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6);
        //Create DB record for order in DB
        try {
            $idc2 = DB::table('b2bsaleslist_orders')
                ->insertGetId(array('applicationCode' => $randapcode,
                    'date' => date("Y-m-d H:i:s"),
                    'duedate' => $this->duedateforpdf("B2BSalesList"),
                    'status' => 'Running',
                    'user' => $request["user_email"]));
        } catch (Exception $e) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Validate for invalid value of market_id
        if (intval($request["market_id"]) === 0) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <market_id> can not be zero ","error_code": 6}]}';
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
            exit();
        }
        //Set default query param
        $q = "?market_id=" . $request["market_id"];
        //Add to Query any filter that is given 
        $filterList = [
            'chamber',
            'postal_code',
            'region',
            'regionUnit',
            'orgtype',
            'results',
        ];
        $q .= $helperCont->addFiltersToQueryString($request, $filterList, $idc1);
        $q .= $helperCont->addDateFiltersToQueryString($request);
        //Update DB order query record
        try {
            DB::table('b2bsaleslist_orders')->where('id', $idc2)->update(array('query' => $q));
        } catch (Exception $e) {
            Log::error("Error on B2B Orders Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }

        //Store to cache as pending appcode order
        Cache::put("pendingpdforders_B2Blistn", $idc2, 30);
        //Trigger Runprocs to start generate file
        $resp = $helperCont->runProcsCurl();
        //Set Response and return
        $r["applicationCode"] = $randapcode;
        $r["messages"][0]["title"] = "the application has been registered";
        $r["messages"][0]["message_code"] = 1;
        http_response_code(200);
        echo json_encode($r);
        $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
        exit();
    }
    /**
     * This method get the pending orders for b2b sales lead list and handle production of the file.
     * Accept $order_id(int)
     * Return String(Void)
     */
    public function updateB2BSalesListOrder($order_id = null)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Get all orders that are still running or failed
        try {
            if ($order_id == null || $order_id <= 0) {
                $orders = DB::table('b2bsaleslist_orders')
                    ->where('status', '<>', 'Completed')
                    ->get();
            } elseif ($order_id == 'rerunFailed') {
                $orders = DB::table('b2bsaleslist_orders')
                    ->where('status', 'Failed')
                    ->get();
            } elseif ($order_id == 'rerunOverdue') {
                $orders = DB::table('b2bsaleslist_orders')
                    ->where('status', 'Running')
                    ->get();
            } else {
                $orders = DB::table('b2bsaleslist_orders')
                    ->where('id', $order_id)
                    ->get();
            }
        } catch (Exception $e) {
            Log::error('Internal Server Error while reading WPD DB for b2b orders list: ' . $e->getMessage());
        }
        //Handle the production for each order
        foreach ($orders as $k => $v) {
            try {
                //Store to cached as pending the current order 
                Cache::put("pendingpdforders_B2Blistn", $v->id, 30);
                //Store to cache as order is running
                if (Cache::has("is_order_running_" . $v->id)) {
                    continue;
                }
                Cache::put("is_order_running_" . $v->id, "yes", 60);
            } catch (Exception $e) {
                Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
            }
            //Try update status of order
            try {
                DB::table('b2bsaleslist_orders')
                    ->where('id', $v->id)
                    ->update(array('link' => null, 'status' => 'Running'));
            } catch (Exception $e) {
                Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
            }
            //Get Bearer token for curls below
            $token = $this->bearerToken();
            //Prepare query for admin panel log
            $q = substr($v->query, 1);
            $q = str_replace('market=', 'market_id=', $q);
            $q = str_replace('postCode', 'postal_code', $q);
            $q = str_replace('turn_over', 'turnOverRanges', $q);
            //If query is set start production
            if ($q != null) {
                //Store to cache running order production
                try {
                    Cache::put("whatisrunningbackgroundB", "data for market query " . $q . " and id:" . $v->id, 2);
                } catch (Exception $e) {
                    Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                }
                //Set Lang of file - Temp. Hardcoded
                $lang = 'el';
                //Set query for XLS (Async) smartfilter service
                $q1 = str_replace('market_id=', 'market=', $q);
                $q1 = str_replace('postal_code', 'postCode', $q1);
                $q1 = str_replace('turn_over', 'turnOverRanges', $q1);
                $smartfilterQuery = '?' . $q1;
                $orderId = $v->id;
                //Get task id from XLS (Async) smartfilter service
                $taskID = $helperCont->getSmartFilterXlsAsyncTaskId($orderId, $smartfilterQuery, $token);
                if (! isset($taskID)) {
                    Log::error('B2B List couldnt get taskID from Service for order ' . $v->id);
                    Cache::put("Error_for_xlsx_" . $v->id, "Δεν δοθηκε task_id απο το service xls", 3600);
                    Cache::forget("is_order_running_" . $v->id);
                    continue;
                }
                Log::info("Start Produce for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                //Set path for file
                $pathr = $helperCont->getFileFolderPath('b2bsaleslist');
                //Prepare & Run Curl 1st iteration of get task data
                sleep(10);
                $data = $helperCont->getSmartFilterXlsAsyncTaskData($orderId, $taskID, $token);
                $status = $data['status'];
                //Check when task is completed
                if (isset($status) && $status == "RUNNING") {
                    $i = 0;
                    while ($status == "RUNNING" && $i < 60) {
                        //Rerun till get completed
                        $data = $helperCont->getSmartFilterXlsAsyncTaskData($orderId, $taskID, $token);
                        if ($data['status'] === 'COMPLETED')
                            $status = "COMPLETED";
                        //Pause and rerun
                        sleep(60); //60sec
                        $i++;
                    }
                    //Catch if something went wrong or still running
                    if ($data['status'] !== 'COMPLETED') {
                        Log::info("Extended Running for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                        try {
                            DB::table('b2bsaleslist_orders')
                                ->where('id', $v->id)
                                ->update(array('link' => null, 'status' => 'Failed'));
                            Cache::put("Error_for_xlsx_" . $v->id, "Τρεχει εκτεταμμενα το order", 3600);
                        } catch (Exception $e) {
                            http_response_code(500);
                            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
                            Cache::forget("is_order_running_" . $v->id);
                            exit();
                        }
                    }
                }
                if (isset($status) && $status == "COMPLETED") {
                    Log::info("Service Response is Complete for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                    $data = isset($data['data']) ? $data['data'] : null;
                    //Get Market Name
                    $market = null;
                    $marketName = null;
                    if (isset($data["data"]["marketNames"])) if (isset($data["data"]["marketNames"]["marketTitle_en"])) {
                        $marketName = $data["data"]["marketNames"]["marketTitle_en"];
                        $market = strtolower($data["data"]["marketNames"]["marketTitle_en"]);
                        $market = str_replace(" ", "-", $market);
                    }
                    //Get List Prepare/Purchase Date
                    $date = date("Ymd");
                    //Set default list sheets
                    $dataFileIdentity = [
                        'marketName' => $marketName,
                        'userEmail' => $v->user,
                        'date' => $date
                    ];
                    $dataSheets = $helperCont->getDataSheetTemplateByLang($lang, $dataFileIdentity);
                    //Set sheet properties for the list
                    $sheetProperties = [
                        'fileIdentity' => [
                        ],
                        'dataDictionary' => [
                        ],
                        'dataSets' => [
                            'header' => [
                                'cell_range' => 'A1:Y1',
                                'font_color' => 'FFFFFF',
                                'font_family' => 'Roboto',
                                'font_size' => '10',
                                'background_color' => '9059C6',
                                'bold' => true,
                                'width' => ['A' => 21.5, 'B' => 21.5, 'C' => 21.5, 'D' => 21.5,
                                    'E' => 21.5, 'F' => 21.5, 'G' => 21.5, 'H' => 21.5, 'I' => 21.5,
                                    'J' => 21.5, 'K' => 21.5, 'L' => 21.5, 'M' => 21.5, 'N' => 21.5,
                                    'O' => 21.5, 'P' => 21.5, 'Q' => 21.5, 'R' => 21.5, 'S' => 21.5,
                                    'T' => 21.5, 'U' => 21.5, 'V' => 21.5, 'W' => 21.5, 'X' => 21.5, 'Y' => 21.5
                                ]
                            ],
                            'body' => [
                                'cell_range' => 'A2:Y',
                                'font_size' => '10',
                                'font_family' => 'Roboto',
                                'background_color' => '9059C6', //For header 
                                'background_color_even_odd' => [ //it sets for the body cells(starting at row:2)
                                    'even' => 'E8DAF6',
                                    'odd' => 'F3F3F3'
                                ]
                            ]
                        ]
                    ];
                    //Check if we have data
                    if (isset($data["data"]["Companies"])) if (count($data["data"]["Companies"]) > 0) {
                        Log::info("Processing Service Response Data is for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                        //Get & Format Data
                        try {
                            $dataSheets['dataSets'] = $helperCont->tranformDataForB2BListTemplate($data["data"]["Companies"]);
                        } catch (\Exception $e) {
                            Cache::put("Error_for_xlsx_" . $v->id, "Προβλημα κατα την προετοιμασια των δεδομενων για taskID: " . $taskID, 3600);
                            Log::error("B2B Sales List - Failed  Transform Data for Order: " . $v->id . ", Service Task: " . $taskID . " with error: " . $e->getMessage());
                        }
                        //Set Paths & Names for file
                        $fileName = isset($market) ? $fileName = "lb-list-universe-" . str_replace('&', 'and', $market) . "-" . $date : "lb-list-universe-" . $date;
                        // $localPath = $pathr.$v->applicationCode;
                        $localPath = $helperCont->getFileFolderPath($pathr . $v->applicationCode);
                        //Set path for WPD S3 bucket
                        $S3link = config("keycloak.Aws_Folder_WPD_Β2Β") . $v->applicationCode . "/" . $fileName . '.xlsx';
                        //Get Excel File
                        try {
                            Log::info("Generating excel file for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                            $helperCont->generateB2BSaleListExcelFileFromTemplate($dataSheets, $localPath, $fileName, $sheetProperties, $lang);
                        } catch (\Exception $e) {
                            Cache::put("Error_for_xlsx_" . $v->id, "Προβλημα κατα την παραγωγη του excel για taskID: " . $taskID, 3600);
                            Log::error("B2B Sales List - Failed Generate Excel for Order: " . $v->id . ", Service Task: " . $taskID . " with error: " . $e->getMessage());
                        }
                        //Check if valid path -> check if not corrupted file
                        if (file_exists($localPath . '/' . $fileName . '.xlsx') && is_file($localPath . '/' . $fileName . '.xlsx')) {
                            //Save it to WPD S3 bucket
                            try {
                                Storage::disk('s3-wpd')->put($S3link, file_get_contents($localPath . '/' . $fileName . '.xlsx'));
                                Log::info("Uploading xlsx for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                            } catch (\Exception $e) {
                                Cache::put("Error_for_xlsx_" . $v->id, "Προβλημα κατα την αποθηκευση στο S3 για taskID: " . $taskID, 3600);
                                Log::error("Failed to Upload File for B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                            }
                        } else {
                            //Update order status 
                            try {
                                DB::table('b2bsaleslist_orders')
                                    ->where('id', $v->id)
                                    ->update(array('link' => null, 'status' => 'Failed'));
                                Log::error("Failed to Store File - Xlsx File was Corrupted, B2B List Order: " . $v->id . ", Service Task: " . $taskID);
                                Cache::put("Error_for_xlsx_" . $v->id, "Xlsx File was Corrupted", 3600);
                            } catch (Exception $e) {
                                Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                            }
                        }

                        //Update order to completed with the final file
                        try {
                            DB::table('b2bsaleslist_orders')
                                ->where('id', $v->id)
                                ->update(array('link' => $S3link, 'status' => 'Completed'));
                            Cache::forget('TaskID_for_order_' . $v->id);
                            Cache::forget("Error_for_xlsx_" . $v->id);
                        } catch (Exception $e) {
                            Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                        }
                        //Delete local file
                        if (file_exists($localPath . '/' . $fileName . '.xlsx')) {
                            try {
                                unlink($localPath . '/' . $fileName . '.xlsx');
                            } catch (Exception $e) {
                                //to add debug
                            }
                        }
                    } else {
                        //Update order status 
                        try {
                            DB::table('b2bsaleslist_orders')
                                ->where('id', $v->id)
                                ->update(array('link' => null, 'status' => 'Failed'));
                            Cache::put("Error_for_xlsx_" . $v->id, "Δωθηκαν κακα δεδομενα για taskID: " . $taskID, 3600);
                            Log::error('B2B List bad data response from from Service for order ' . $v->id . ' with taskID: ' . $taskID);
                        } catch (Exception $e) {
                            Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                        }
                    }
                }
                //if it fail
                if (! isset($status) || $status != "COMPLETED") {
                    //Update order status 
                    try {
                        DB::table('b2bsaleslist_orders')
                            ->where('id', $v->id)
                            ->update(array('link' => null, 'status' => 'Failed'));
                        Cache::put("Error_for_xlsx_" . $v->id, "Δεν υπαρχει status ή το status ειναι failed, δηλαδη μαλλον δωθηκαν κακα δεδομενα για taskID: " . $taskID, 3600);
                    } catch (Exception $e) {
                        Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                    }
                }
                //Clean cache
                try {
                    Cache::forget("is_order_running_" . $v->id);
                } catch (Exception $e) {
                    Log::error('Internal Server Error while writting WPD DB for b2b order list: ' . $v->id . '-' . $e->getMessage());
                }
            }
        }
        return "ok";
    }
    /**
     * This Function returns the b2b sales lead list if is completed or info about its status.
     * Accepts Request with application_code, api_token, user_email, user_role
     * Return JSON with status and based64 string file or just status
     */
    public function MarketListGet(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        $hasError = false;
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'B2BSalesListGet', ['applicationCode']);
        //Set application code
        $ac = $request["applicationCode"];
        //Get Application Order from DB orders
        try {
            $order = DB::table('b2bsaleslist_orders')->where('applicationCode', $ac)->get();
        } catch (Exception $e) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while read call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Set Response based on found status or not found order
        if (count($order) === 0) {
            //Prepare and send Not found appcode     
            $r["messages"][0]["title"] = "application not found";
            $r["messages"][0]["message_code"] = 7;
            $htc = "404";
            http_response_code($htc);
            echo json_encode($r);
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);
            exit();
        } elseif (reset($order)[0]->status !== "Completed") {
            //Set Response  file is running 
            $r["applicationCode"] = $ac;
            $r["status"] = "Running";
            $r["file"] = null;
            http_response_code(200);
            echo json_encode($r);
            //Update DB call record
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

            exit();
        } else {
            //Set basic S3 links and filename
            $s3link = reset($order)[0]->link;
            $linkList = explode("/", $s3link);
            $fileName = $linkList[2];
            $b2bListFile = null;
            //Check if exist in WPD S3 bucket
            if (Storage::disk('s3-wpd')->exists(config("keycloak.Aws_Folder_WPD_Β2B") . $s3link)) {
                //Get File from WPD S3 bucket
                $b2bListFile = Storage::disk('s3-wpd')->get(config("keycloak.Aws_Folder_WPD_Β2B") . $s3link);
            } else {
                //Update DB order record
                try {
                    DB::table('b2bsaleslist_orders')
                        ->where('applicationCode', $ac)
                        ->update(array('link' => null, 'status' => 'Failed'));
                } catch (Exception $e) {
                    http_response_code(500);
                    echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
                    exit();
                }
                //Set Running 
                $r["applicationCode"] = $ac;
                $r["status"] = "Running";
                $r["file"] = null;
                http_response_code(200);
                //Send repsonse                   
                echo json_encode($r);
                //Update DB call record
                try {
                    DB::table('b2bsaleslist_calls')->where('id', $idc1)->update(array('completed' => true));
                } catch (Exception $e) {
                    //Nothing, it's for debug
                }
                $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

                exit();
            }
            //Get zip with file
            $file = $helperCont->addFileToZip($b2bListFile, $fileName, 'b2bsaleslist', $ac);
            if (isset($file))
                $file64 = base64_encode($file);
            //Set Response   
            $r["applicationCode"] = $ac;
            $r["status"] = "Completed";
            $r["file"]["mime"] = "application/zip;base64";
            $r["file"]["data"] = $file64;
            //Return Response
            http_response_code(200);
            echo json_encode($r);
            //Update DB call record
            $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

            exit();
        }

    }

    public function getDataForTaskId($request)
    {
        $taskId = $request->taskId;
        $token = $this->bearerToken();
        $results = [
            'data' => null,
            'status' => null
        ];
        //Prepare & Curl 1st iteration of get task data
        $qTask = "?id=" . $taskId;
        $curlXlsCompanies = $this->prepareGetCurl(config("keycloak.SmartFilterTaskGet"), $token, $qTask, 120);
        $json = curl_exec($curlXlsCompanies);
        $err = curl_error($curlXlsCompanies);
        curl_close($curlXlsCompanies);

        //Check 1st iteration of getting task response
        $data = json_decode($json, true);
        $results['data'] = $data;
        return $results;
    }

    /**
     * Method that accepts an Request with vat list 
     * This method requests from Watchlist endpoint the person vats from the request["vat_list"]
     * Returns (JSON)Company List(Array) with Persons
     */
    public function PersonCardList(Request $request)
    {
        //Set Helpers Controller && error   
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'PersonCards', ['vat_list']);
        http_response_code(200);
        //Get Token & Prepare Curls for Watchlist Data

        // Lang option
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }

        $post = json_encode($request["vat_list"]);
        $curl = $this->preparePostCurl(config("keycloak.Elastic_Cards") . '?lang=' . $lang, $this->bearerToken(), $post, 50);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        $resj = json_decode($res, true);

        foreach ($resj as $k => $v) {
            $resj[$k]["score"] = "high";
            if (isset($v["isCompany"])) {
                if ($v["isCompany"]) {
                    $resj[$k]["isCompany"] = "1";
                } else {
                    $resj[$k]["isCompany"] = "0";
                }
            } else {
                $resj[$k]["isCompany"] = "0";
            }

            if (! isset($v["name"])) {
                $resj[$k]["name"] = null;
            }
            if (! isset($v["vat"])) {
                $resj[$k]["vat"] = null;
            }
            if (! isset($v["managementCnt"])) {
                $resj[$k]["managementCnt"] = '0';
            }
            if (! isset($v["ownershipCnt"])) {
                $resj[$k]["ownershipCnt"] = '0';
            }
            if (! isset($v["isDebtor"])) {
                $resj[$k]["isDebtor"] = false;
            }
            if (! isset($v["hasAuctions"])) {
                $resj[$k]["hasAuctions"] = false;
            }
            if (! isset($v["hasFines"])) {
                $resj[$k]["hasFines"] = false;
            }


        }

        echo json_encode($resj);
        // $mes = $this->getPersonsDataforSearched($request["vat_list"], $lang);
        // Show Results
        // echo json_encode($mes);
        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'PersonCards');

        exit();
    }

    /**
     * Method that accepts an Request with vat list for history list
     * This method requests from Watchlist endpoint the vats from the request["vat_list"]
     * Returns (JSON)Company List(Array) with Companies
     */
    // Platform should look for "marketName" instead of "market", "isActive" instead of "active" and field "isCompany" should be removed. 
    public function CompanyCardList(Request $request)
    {
        //Set Helpers Controller && error   
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'CompanyCards', ['vat_list']);
        http_response_code(200);
        //Get Token & Prepare Curls for Watchlist Data

        // Lang option
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }

        $post = json_encode($request["vat_list"]);
        $curl = $this->preparePostCurl(config("keycloak.Elastic_Cards") . '?lang=' . $lang, $this->bearerToken(), $post, 50);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        $resj = json_decode($res, true);

        foreach ($resj as $k => $v) {
            if (isset($v["gemhNumber"])) {
                $resj[$k]["gemh"] = $v["gemhNumber"];
                unset($resj[$k]["gemhNumber"]);
            } else {
                $resj[$k]["gemh"] = null;
            }
            if (isset($v["market"])) {
                $resj[$k]["marketName"] = $v["market"];
                // unset($resj[$k]["market"]);
            } else {
                $resj[$k]["marketName"] = null;
                $resj[$k]["market"] = null;
            }
            if (isset($v["topCpaName"])) {
                $resj[$k]["mainCpaName"] = $v["topCpaName"];
                unset($resj[$k]["topCpaName"]);
            } else {
                $resj[$k]["mainCpaName"] = null;
            }
            if (isset($v["active"])) {
                $resj[$k]["isActive"] = $v["active"];
                // unset($resj[$k]["active"]);
            } else {
                $resj[$k]["isActive"] = null;
            }
            if (isset($v["grossProfitAmount"])) {
                $resj[$k]["turnOverAmount"] = $v["grossProfitAmount"];
                unset($resj[$k]["grossProfitAmount"]);
                if ($resj[$k]["turnOverAmount"] == -1) {
                    $resj[$k]["turnOverAmount"] = null;
                }
            } else {
                $resj[$k]["turnOverAmount"] = null;
            }
            if (isset($v["grossProfitYear"])) {
                $resj[$k]["turnOverYear"] = $v["grossProfitYear"];
                unset($resj[$k]["grossProfitYear"]);
            } else {
                $resj[$k]["turnOverYear"] = null;
            }
            if (isset($v["score"])) {
                unset($resj[$k]["score"]);
            }

            if (! isset($v["name"])) {
                $resj[$k]["name"] = null;
            }
            if (! isset($v["vat"])) {
                $resj[$k]["vat"] = null;
            }
            if (! isset($v["address"])) {
                $resj[$k]["address"] = null;
            }
            if (! isset($v["orgType"])) {
                $resj[$k]["orgType"] = null;
            }
            if (! isset($v["isDebtor"])) {
                $resj[$k]["isDebtor"] = false;
            }
            if (! isset($v["hasAuctions"])) {
                $resj[$k]["hasAuctions"] = false;
            }
            if (! isset($v["hasFines"])) {
                $resj[$k]["hasFines"] = false;
            }
            if (! isset($v["incorporationDate"])) {
                $resj[$k]["incorporationDate"] = null;
            }

            $resj[$k]["isCompany"] = '1';
        }
        $ret = array();
        $ret["Companies"] = $resj;

        echo json_encode($ret);

        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'CompanyCards');

        exit();
    }


    /**
     * Method that accepts an Request with vat list for history list
     * This method requests from Watchlist endpoint the vats from the request["vat_list"]
     * Returns (JSON)Company List(Array) with Companies
     */
    public function CompanyList(Request $request)   //to be depricated
    {
        //Set Helpers Controller && error   
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'CompanyList', ['vat_list']);
        http_response_code(200);
        //Get Token & Prepare Curls for Watchlist Data

        // Lang option
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }
        // $mes = $this->getDataFromGsisWatchlist($request["vat_list"], $lang, "default");
        $vatlist_start = $request["vat_list"];
        $vatlist_comp = $request["vat_list"];
        $dataS = $this->getDataCompaniesFromS3($vatlist_comp, $lang);
        $vatlist_comp = $dataS[0];
        $dataW = $this->getDataFromGsisWatchlist($vatlist_comp, $lang, "default");
        $mes["Companies"] = $this->addDeleteFieldsForMyListsWatchlist($this->dataMergeFromWatchlist($vatlist_start, $dataW["Companies"], $dataS[1]), $lang);

        $ret["List"] = array();
        foreach ($vatlist_start as $k => $v) {
            $found = false;
            foreach ($mes as $k1 => $v1) {
                if (! $found) {
                    foreach ($v1 as $k2 => $v2) {
                        if ($v == $v2["vat"] && ! $found) {
                            array_push($ret["List"], $v2);
                            $found = true;
                        }
                    }
                }
            }
        }
        $mes["Companies"] = $ret["List"];
        //Show Results
        echo json_encode($mes);
        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Watchlist');

        exit();
    }

    /**
     * Method that accepts an Request with vat list for watchlist
     * This method requests from Watchlist endpoint the vats from the request["vat_list"]
     * Returns (JSON)Company List(Array) with Companies
     */
    public function WatchList(Request $request)
    {
        //Set Helpers Controller && error   
        $helperCont = (new WebPortalHelperControllerV1);
        $idc1 = $helperCont->initializeRequestHelper($request, 'webportal_calls', 'KYC Watchlist Elastic', ['vat_list']);
        http_response_code(200);
        
        // Lang option
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }
        //new endpoint
        $post = json_encode($request["vat_list"]);
        $curl = $this->preparePostCurl(config("keycloak.Elastic_Watchlist") . '?lang=' . $lang, $this->bearerToken(), $post, 50);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        echo $res;
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Watchlist Elastic');
        exit();       
    }

    public function addDeleteFieldsForMyListsWatchlist($ar, $lang = 'el')
    {
        $fields = array("name" => null, "vat" => null, "gemh" => null, "address" => null, "orgType" => null, "mainCpaName" => null, "market" => null, "office" => null,
            "turnOverAmount" => null, "turnOverYear" => null, "isDebtor" => false, "hasFines" => false, "hasAuctions" => false, "publicDebt" => null,
            "publicDebtYear" => null, "efkaDebt" => null, "efkaDebtYear" => null, "diaygeia" => 0, "espa" => 0, "auctionsCredit" => null, "auctionsDebts" => null,
            "active" => true, "incorporationDate" => null, "earnings" => null, "isCompany" => "1");
        $fields2 = array("name", "vat", "gemh", "address", "orgType", "mainCpaName", "market", "office", "turnOverAmount", "turnOverYear", "isDebtor", "hasFines",
            "hasAuctions", "publicDebt", "publicDebtYear", "efkaDebt", "efkaDebtYear", "diaygeia", "espa", "auctionsCredit", "auctionsDebts", "active", "incorporationDate",
            "earnings", "isCompany");

        foreach ($ar as $k => $v) {
            foreach ($v as $k1 => $v2) {
                if (! in_array($k1, $fields2)) {
                    unset($ar[$k][$k1]);
                }
            }
            foreach ($fields as $k2 => $v2) {
                if (! isset($v[$k2])) {
                    $ar[$k][$k2] = $v2;
                }
            }

            if (isset($v["turnOverAmount"]) && $v["turnOverAmount"] == "") {
                $ar[$k]["turnOverAmount"] = null;
            }
            if ($ar[$k]["orgType"] == null) {
                if ($lang == "el") {
                    $ar[$k]["orgType"] = "Φυσικό Πρόσωπο";
                } else {
                    $ar[$k]["orgType"] = "Person";
                }
            }
        }

        return $ar;

    }


    public function getDataFromGsisWatchlist($vatlist = array(), $lang = "gr", $url = "default")
    {

        $token = $this->bearerToken();
        $helperCont = (new WebPortalHelperControllerV1);
        $mh = curl_multi_init();
        //CURL request to Watchlist with a vat's list
        $postArray = [
            "vat" => $vatlist
        ];
        $post = json_encode($postArray);
        if ($url == "default") {
            $endpoint = config("keycloak.watchlist");
        } elseif ($url == "search") {
            $endpoint = config("keycloak.watchlist_for_search");
        }
        $curlWatchlist = $this->preparePostCurl($endpoint . "?lang=" . $lang, $token, $post, 40);
        $resp = curl_exec($curlWatchlist);

        //Set Response
        $mes = [
            "Companies" => []
        ];

        try {
            $dataWatchlist = isset($resp) ? $resp : null;
            $mes['Companies'] = $helperCont->getWatchlistData($dataWatchlist);
        } catch (Exception $e) {
            Log::error('KYC Watchlist - Failed get data from services. ' . $e->getMessage());
        }
        return $mes;
    }

    public function alertsAddAlertStream(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request)) {
            exit();
        }
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role', 'productName', 'startDate']);
        if ($request["user_email"] == null || $request["user_email"] == "") {
            http_response_code(400);
            echo '{"errors":[{"title":"field <user_email> is empty ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }

        if (isset($request["endDate"])) {
            $qenddate = "&endDate=" . $request["endDate"];
        } else {
            $qenddate = "";
        }

        if (isset($request["vat"])) {
            if ($request["vat"] != null) {
                if (! isset($request["subBlockName"])) {
                    http_response_code(400);
                    echo '{"errors":[{"title":"field <subBlockName> is missing ","error_code": 6}]}';
                    DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
                    exit();
                }
                $sbname = "";
                foreach ($request["subBlockName"] as $k => $v) {
                    $sbname .= "&dataBlockName=" . urlencode($v);
                }
                $token = $this->bearerToken();
                $ent = $this->EntityCurl($request["vat"], $token);
                $jent = json_decode($ent,true);
                $tent = "";
                if (isset($jent["isCompany"]) && $jent["isCompany"]) {
                    $tent .="&isGsisCompany=true";
                } else {
                    $tent .="&isGsisCompany=false";
                }
                if (isset($jent["isLegalEntity"]) && $jent["isLegalEntity"]) {
                    $tent .="&isLegalEntity=true";
                } else {
                    $tent .="&isLegalEntity=false";
                }
                $p["username"] = $request["user_email"];
                $post = json_encode($p);
                $coptq = config("keycloak.alertAddStream") . "?vat=" . $request["vat"] . "&productName=" . urlencode($request["productName"]) . $tent . "&startDate=" . $request["startDate"] . $qenddate . $sbname . "&role=" . urlencode($request["user_role"]);                
            }
        }

        if (isset($request["market_id"])) {
            if ($request["market_id"] != null) {
                $p["username"] = $request["user_email"];
                $post = json_encode($p);
                $coptq = config("keycloak.alertAddStreamMarket") . "?marketId=" . $request["market_id"] . "&productName=" . urlencode($request["productName"]) . "&startDate=" . $request["startDate"] . $qenddate;
            }

        }
        if (! isset($request["vat"]) && ! isset($request["market_id"])) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <vat> or field <market_id> is missing ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }


        $token = $this->bearerToken();

        $p["username"] = $request["user_email"];
        $post = json_encode($p);
        $curl = $this->preparePostCurl($coptq, $token, $post, 60);
        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        // return $res;
        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = 503;
            http_response_code($htc);
        } else {
            $r["messages"][0]["title"] = "alerts updated";
            $r["messages"][0]["body"] = $res;
            $r["messages"][0]["message_code"] = 3;
            http_response_code(200);
        }
        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
        echo json_encode($r);
        exit();

    }

    public function alertsUpdateRegistration(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role', 'registrationId']);

        $token = $this->bearerToken();

        $p["registrationId"] = $request["registrationId"];
        $post = json_encode($p);
        $curl = $this->preparePostCurl(config("keycloak.alertRegUpd") . "?customerName=" . urlencode($request["user_email"]), $token, $post, 60);
        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = "503";
            http_response_code($htc);
        } else {
            $r["messages"][0]["title"] = "alerts updated";
            $r["messages"][0]["body"] = $res;
            $r["messages"][0]["message_code"] = 3;
            http_response_code(200);
        }
        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
        echo json_encode($r);
        exit();
    }

    public function alertsUser(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role']);


        if ($request["user_email"] == null || $request["user_email"] == "") {
            http_response_code(400);
            echo '{"errors":[{"title":"field <user_email> is empty ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }

        $token = $this->bearerToken();

        $curl = $this->prepareGetCurl(config("keycloak.alertUser"), $token, "?customerName=" . $request["user_email"], 60);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = "503";
            http_response_code($htc);
        } else {
            $r = json_decode($res, true);
            http_response_code(200);
        }
        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
        echo json_encode($r);
        exit();
    }

    public function alertsUserStream(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role']);
        if ($request["user_email"] == null || $request["user_email"] == "") {
            http_response_code(400);
            echo '{"errors":[{"title":"field <user_email> is empty ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }
        $token = $this->bearerToken();

        $curl = $this->prepareGetCurl(config("keycloak.alertUserStream"), $token, "?customerName=" . $request["user_email"], 60);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = "503";
            http_response_code($htc);
        } else {
            $r = json_decode($res, true);
            http_response_code(200);
        }
        // DB::table('pdf_report_calls')->where('id',$idc1)->update(array('completed'=>true));                             
        echo json_encode($r);
        exit();
    }

    public function statePackage(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role', 'productName']);

        if ($request["user_email"] == null || $request["user_email"] == "") {
            http_response_code(400);
            echo '{"errors":[{"title":"field <user_email> is empty ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }

        if (! isset($request["state"])) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <state> is missing ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        } else {
            if ($request["state"] == "enabled") {
                $st = "true";
            } else {
                $st = "false";
            }
        }

        $token = $this->bearerToken();
        $post = array();
        $curl = $this->preparePostCurl(config("keycloak.alertsStatePackage") . "?customerName=" . urlencode($request["user_email"]) . "&productName=" . urlencode($request["productName"]) . "&startDate=" . $request["startDate"] . "&enable=" . $st, $token, $post, 60);
        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = "503";
            http_response_code($htc);
        } else {
            $r["messages"][0]["title"] = "alerts updated";
            $r["messages"][0]["body"] = $res;
            $r["messages"][0]["message_code"] = 3;
            http_response_code(200);
        }

        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
        echo json_encode($r);
        exit();
    }

    public function stateVat(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'AddAlertStream', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request)) {
            exit();
        }
        $helperCont->checkIfSet($request, $idc1, ['user_email', 'user_role', 'productName', 'vat']);

        if ($request["user_email"] == null || $request["user_email"] == "") {
            http_response_code(400);
            echo '{"errors":[{"title":"field <user_email> is empty ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }
        $sarr = array();
        if (! isset($request["subBlockName"])) {
            $sarr = array("Basic", "Credit", "BusinessNetwork", "Events", "Unfavorable", "SwornAudit", "Performance", "Money", "Market", "News", "Groups");
        } else {
            $sarr = $request["subBlockName"];
        }

        $sbname = "";
        foreach ($sarr as $k => $v) {
            $sbname .= "&dataBlockName=" . urlencode($v);
        }

        $st = "false";
        if (! isset($request["state"])) {
            http_response_code(400);
            echo '{"errors":[{"title":"field <state> is missing ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        } else {
            if ($request["state"] == "enabled") {
                $st = "true";
            }
        }

        $token = $this->bearerToken();
        $post = array();

        $curl = $this->preparePostCurl(config("keycloak.alertsState") . "?customerName=" . urlencode($request["user_email"]) . "&productName=" . urlencode($request["productName"]) . "&startDate=" . $request["startDate"] . "&vat=" . $request["vat"] . "&enable=" . $st . "&role=" . urlencode($request["user_role"]) . $sbname, $token, $post, 60);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err || isset(json_decode($res, true)["status"])) {
            $r["messages"][0]["title"] = "Service Unavailable - This indicates that something unexpected happened on the server side (It can be anything like server overload, some parts of the system failed, etc.).";
            $htc = "503";
            http_response_code($htc);
        } else {
            $r["messages"][0]["title"] = "alerts updated";
            $r["messages"][0]["body"] = $res;
            $r["messages"][0]["message_code"] = 3;
            http_response_code(200);
        }

        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
        echo json_encode($r);
        exit();
    }

    /**
     * Method that accepts an Vat
     * This method get from S3 the PDF file,then stores it locally. Finaly returns the link to the file.
     * Returns (String) the link(path to the pdf file)  
     */
    public function getS3Report($vat, $ac)
    {
        $orderLink = null;
        $s3filepath = config("keycloak.Aws_Folder_BR") . $vat . '.pdf';

        //get report from s3 bucket BR
        $reportFile = Storage::disk('s3')->get($s3filepath);

        $fileName = $ac . "/" . $vat . '.pdf';
        $link = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
        //Save it to WPD S3 bucket
        Storage::disk('s3-wpd')->put($link, $reportFile);

        return $link;
    }

    /**
     * Method that accepts an path (String) = vat , ac  and bucket (String) = BR, WPD-BR
     * This method evaluated if the report exist in S3 bucket (wpd/br). If yes, change isExist status  returns true
     * Returns (Boolean) true / false 
     */
    public function existsReportInS3($path, $bucket)
    {
        $isExist = false;

        if ($bucket == "BR") {
            $filepath = config("keycloak.Aws_Folder_BR") . $path . '.pdf';
            if (Storage::disk('s3')->exists($filepath)) {
                $isExist = true;
            }
        }

        if ($bucket == "WPD-BR") {
            $filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $path;
            if (Storage::disk('s3-wpd')->exists($filepath)) {
                $isExist = true;
            }
        }


        return $isExist;
    }
    /**
     * The function accepts an array of IDs and an authentication token
     * This method is responsible for fetching and merging the treeviews from the results miner for all specified inside the array IDs
     * Returns (Array) Tree View of all IDs specified merged 
     */
    public function getTreeViewPerID($id_array, $token)
    {
        //Initialize empty array to store individual tree views
        $data = [];

        //For each id inside the array provided as input fetch the Tree View from Results Miner
        foreach ($id_array as $id) {
            $request_parameters = "?id=" . $id;
            //CURL request to Results Miner to get tree view for each type ID
            $helperCont = (new WebPortalHelperControllerV1);
            $cb = $this->prepareGetCurl(config("keycloak.ResultsTreeView"), $token, $request_parameters, 20);

            $response = curl_exec($cb);
            $err = curl_error($cb);
            $httpcode = curl_getinfo($cb, CURLINFO_HTTP_CODE);
            curl_close($cb);

            //Error Handling
            if ($err) {
                http_response_code(500);
                echo "{'errors':[{'title':'Internal Server Error while fetchingtree variable per ID','error_code': '10'}]}";
                exit();
            }
            if ($httpcode != 200) {
                http_response_code(500);
                echo "{'errors':[{'title':'Internal Server Error while fetching results tree view','error_code': '10'}]}";
                exit();
            }

            //Merge each tree view to the data array
            $data = $data + json_decode($response, true)["data"];
        }

        //Unset last variable of foreach loop as mentioned in documentation
        unset($id);
        return $data;
    }

    /**
     * This is a helper function that accepts a merged array of tree view objects
     * The functions transforms the tree view intoa populated form
     * Returns (Array)
     */
    public function transformTreeViewPopulated($merged_tree)
    {
        foreach ($merged_tree as $treeKey => $treeValue) {
            foreach ($treeValue["sections"] as $sectionsKey => $sectionsValue) {
                if (isset($merged_tree[$treeKey]["sections"][$sectionsKey]["total_variable_id"])) {
                    unset($merged_tree[$treeKey]["sections"][$sectionsKey]["total_variable_id"]);
                }
                foreach ($sectionsValue["subsections"] as $subsectionsKey => $subsectionsValue) {
                    if (isset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["total_variable_id"])) {
                        unset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["total_variable_id"]);
                    }
                    foreach ($subsectionsValue["blocks"] as $blocksKey => $blocksValue) {
                        if (isset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["blocks"][$blocksKey]["variables"])) {
                            unset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["blocks"][$blocksKey]["variables"]);
                        }

                        if (isset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["blocks"][$blocksKey]["total_variable_id"])) {
                            unset($merged_tree[$treeKey]["sections"][$sectionsKey]["subsections"][$subsectionsKey]["blocks"][$blocksKey]["total_variable_id"]);
                        }
                    }
                }

            }
        }
        return $merged_tree;
    }

    /**
     * This function get all object listed in s3 bucket -> AWS_folder BR
     * Return Array of strings 
     */
    public function getS3ReportsList()
    {
        $list = Storage::disk('s3')->allFiles(config("keycloak.Aws_Folder_BR"));
        return $list;
    }

    public function templates(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        header('Content-type: application/json');
        $idc1 = DB::table('pdf_report_calls')->insertGetId(array('date' => date("Y-m-d H:i:s"), 'service' => 'ReportGet', 'request' => json_encode($request->all()), 'completed' => false));
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        $helperCont->checkIfSet($request, $idc1, ['data']);
        $dc = $request["data"];


        if ($dc == "report") {

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $fPath = public_path() . '\templates\\' . "demo-kyc-report-999999999-v.20221128.pdf";
            } else {
                $fPath = public_path() . '/templates/' . "demo-kyc-report-999999999-v.20221128.pdf";
            }
        } elseif ($dc == "list") {

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $fPath = public_path() . '\templates\\' . "demo-b2b-leads-hotels-v.20221129.xlsx";
            } else {
                $fPath = public_path() . '/templates/' . "demo-b2b-leads-hotels-v.20221129.xlsx";
            }
        } else {
            http_response_code(400);
            echo '{"errors":[{"title":"field <data> is missing, use <report> or <list> ","error_code": 6}]}';
            DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));
            exit();
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $path = storage_path() . '\templates\tosend';
        } else {
            $path = storage_path() . '/templates/tosend';
        }
        if (! is_dir($path)) {
            mkdir($path, 0755, TRUE);
        }

        $zip = new ZipArchive();
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $zip->open($path . '\\' . $dc . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        } else {
            $zip->open($path . '/' . $dc . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        }

        $relativePath = substr($fPath, strlen(public_path() . '\templates\\') + 1);
        $zip->addFile($fPath, $relativePath);

        $zip->close();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $file = file_get_contents(storage_path() . '\templates\tosend\\' . $dc . '.zip');
        } else {
            $file = file_get_contents(storage_path() . '/templates/tosend/' . $dc . '.zip');
        }
        $file64 = base64_encode($file);

        $r["applicationCode"] = null;
        $r["status"] = "Completed";
        $r["file"]["mime"] = "application/zip;base64";
        $r["file"]["data"] = $file64;

        // if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        //     unlink(public_path().'\templates\tosend\\'.$dc.'.zip');  
        // } else {
        //     unlink(public_path().'/templates/tosend/'.$dc.'.zip'); 
        // } 
        http_response_code(200);
        echo json_encode($r);
        DB::table('pdf_report_calls')->where('id', $idc1)->update(array('completed' => true));

        exit();
    }
    /**
     * This function get list of b2b order subcriptions ids to get their info
     * It accepts the order id/id's, user email, api_token
     * Returns ARRAY(json) with order subscriptions details vat count,order id, date, market name, market filter
     */
    public function MarketOrdersSubscriptions(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        $hasError = false;
        //define lang
        if (isset($request["lang"])) {
            if ($request["lang"] == "el" || $request["lang"] == "gr") {
                $lang = "el";
            } elseif ($request["lang"] == "en" || $request["lang"] == "eng") {
                $lang = "en";
            } else {
                $lang = $request["lang"];
            }
        } else {
            $lang = "el";
        }
        $idc1 = $helperCont->initializeRequestHelper($request, 'b2bsaleslist_calls', 'B2BSalesSubscriptions', ['order_id', 'user_email']);
        //Set Basic Variables
        $order_ids = $request["order_id"];
        $user = $request["user_email"];
        $results = array();
        $hasFreshLiveOrder = false;
        //Join order ids for cache name x1_x2_x3...
        $order_idsJoined = implode("_", $order_ids);
        //Get APP.Enviroment for B2bLiveCounts and Cache
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Check if is cached 
        if (Cache::has("getB2BSubscriptions" . $user . "_" . $order_idsJoined . "_" . $environmt . '_' . $lang)) {
            $results = Cache::get("getB2BSubscriptions" . $user . "_" . $order_idsJoined . "_" . $environmt . '_' . $lang);
        } else {
            //Get the auth token
            $token = $this->bearerToken();
            //Set Path for S3 Bucket
            $filepath = config("keycloak.Aws_Folder_B2B_STATS") . env('APP_Env') . '/file.json';          
            //Retrieve Cached Markets response if its available
            if (Storage::disk('s3-b2b')->exists($filepath)) {
                //Get Market stats 
                $marketStatsRes = json_decode(Storage::disk('s3-b2b')->get($filepath), true);
            } else {
                //Get Markets Name 
                $curl = $this->prepareGetCurl(config("keycloak.MarketStats"), $token, '', 70);
                $resp = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);
                $marketStatsRes = json_decode($resp, true);
                //Set Error if something with MarketStats curl or token happend
                if ($err || ! isset($marketStatsRes["lb_markets"])) {
                    $hasError = true;
                    Log::error('B2B Subscription Info - Market Stats failed to retrieve');
                }
                //Check if some error has occurred and exit
                if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $hasError, $token))
                    exit();
                //Store market stats for subs (response) to cached
                Cache::put("MarketStatsforsubs" . $environmt, $resp, config("keycloak.SmartFilterCacheTime"));
            }
            //Set data resources for the order info preparation
            $dataResource = [
                'lang' => $lang,
                'token' => $token,
                'environmt' => $environmt,
                'lb_markets' => $marketStatsRes["lb_markets"]
            ];
            // Get/Format Data for each order found
            foreach ($order_ids as $k => $id) {
                //Try get order from DB
                try {
                    $order = DB::table('b2bsaleslead_user_to_order')
                        ->where('order_id', $id)
                        ->where("user", $user)
                        ->get();
                } catch (Exception $e) {
                    http_response_code(500);
                    Log::error('B2B Subscription Info - User-Orders select are Failed to retrieve');
                    echo "{'errors':[{'title':'Internal Server Error while reading from WPD DB','error_code': '10'}]}";
                    exit();
                }
                //If Order Exists
                if (isset($order)) if (count($order) > 0) {
                    //Get order info
                    $orderData = $helperCont->getMarketOrderInfoFormatted($order, $dataResource);
                    //Set order info to results
                    array_push($results, $orderData['orderInfo']);
                    //Check if some error has occurred and exit
                    if ($helperCont->handleErrors($idc1, 'b2bsaleslist_calls', $orderData['hasError'], $token)) {
                        exit();
                    }
                }
            }
            // Store to cache for 30min If fresh live order lag time pass
            if (count($order_ids) > 0) {
                $lastOrderId = $order_ids[count($order_ids) - 1];
                $hasFreshLiveOrder = $helperCont->isNewlyLiveOrder($lastOrderId, $user);
                if (! $hasFreshLiveOrder) {
                    try {
                        Cache::put("getB2BSubscriptions" . $user . "_" . $order_idsJoined . "_" . $environmt . '_' . $lang, $results, config("keycloak.B2BSubscriptionsCacheTime"));
                    } catch (Exception $e) {
                        // Nothing..
                    }
                }
            }
        }
        //Return Responses
        http_response_code(200);
        echo json_encode($results);
        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'b2bsaleslist_calls', false, null);

        exit();
    }
    /**
     * This function get list of kyc searched results and make curls to cache first 5 results profiles data with [vat]
     * It accepts an Array $results with [][vat] and token(string)
     */
    public function CacheKycSearchedProfiles($results, $token, $lang, $ent="com",$trig="search")
    {
        //Set Request body
        $postBody["api_token"] = env("WPD_API_TOKEN_STAGING_and_PROD");
        $postBody["user_email"] = "wpdsearchcache@lbsuite.eu";
        $postBody["user_role"] = "dev";
        $postBody["update"] = true;
        $postBody["ttl"] = "extended"; // Extented curl timeout
        $postBody["catchCacheRecord"] = false; // Prevent update Last_searched for not viewing profile
        $postBody["lang"] = $lang;
        $environmt = config('keycloak.APP_ENVIROMENT');
        //Get first 5 results
        $data = array_slice($results, 0, 3);
        $cached = "";
        
        //Trigger api calls for all datablock & lvls
        foreach ($data as $k => $v) {
            //Check to not cache already cached vats            
            if ($ent=="com") {
                $e1 = "company";
            } else {
                if ($v["isCompany"]=="1") {
                    $e1 = "company"; 
                } else {
                    $e1 = "person";
                }
            }
            $postBody["vat"] = $v["vat"];

            if ($trig=="search") {
                if ($ent=="com") {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],
                        ["datablock" => "Performance", "level" => "0"],
                        ["datablock" => "BusinessNetwork", "level" => "0"],
                        ["datablock" => "Unfavorable", "level" => "0"],
                        ["datablock" => "Credit", "level" => "0"],
                        ["datablock" => "SwornAudit", "level" => "0"],
                        ["datablock" => "Events", "level" => "0"],
                        ["datablock" => "Market", "level" => "0"],
                        ["datablock" => "Money", "level" => "0"],
                        ["datablock" => "Groups", "level" => "0"]
                        // ["datablock" => "News", "level" => "0"],
                    ];
                } else {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],                   
                        ["datablock" => "BusinessNetwork", "level" => "0"],
                        ["datablock" => "Unfavorable", "level" => "0"]
                    ];
                }
                
                $postBody["data"] = array();
                //Make Curl for Lv0 Company profiles data
                foreach ($tmpl as $k2 => $v2) {
                    if (! Cache::has("getCompanyKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt) && ! Cache::has("getPersonKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt)) {
                        array_push($postBody["data"], $v2);
                        $cached .= "[" . $v["vat"] . "-" . $v2["datablock"] . "-" . $v2["level"] . "] ";
                    }
                }
                $post = json_encode($postBody);
                $curl = $this->preparePostCurl(env("APP_URL_inside") . "/api/v1/WebPortal/".$e1."/overview", '', $post, 60);
                $res = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);
            } else {

                if ($ent=="com") {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],
                        ["datablock" => "Performance", "level" => "1"],
                        ["datablock" => "BusinessNetwork", "level" => "1"],
                        ["datablock" => "Unfavorable", "level" => "1"],
                        ["datablock" => "Credit", "level" => "1"],
                        ["datablock" => "SwornAudit", "level" => "1"],
                        ["datablock" => "Events", "level" => "1"],
                        ["datablock" => "Market", "level" => "1"],
                        ["datablock" => "Money", "level" => "1"],
                        ["datablock" => "Groups", "level" => "1"]
                        // ["datablock" => "News", "level" => "0"],
                    ];
                } else {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],                   
                        ["datablock" => "BusinessNetwork", "level" => "1"],
                        ["datablock" => "Unfavorable", "level" => "1"]
                    ];
                }
                
                $postBody["data"] = array();
                //Make Curl for Lv1 Company profiles data
                foreach ($tmpl as $k2 => $v2) {
                    if (! Cache::has("getCompanyKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt) && ! Cache::has("getPersonKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt)) {
                        // $postBody["data"] = [$v2];
                        array_push($postBody["data"], $v2);
                        $cached .= "[" . $v["vat"] . "-" . $v2["datablock"] . "-" . $v2["level"] . "] ";
                    }
                }
                $post = json_encode($postBody);
                $curl = $this->preparePostCurl(env("APP_URL_inside") . "/api/v1/WebPortal/".$e1."/overview", '', $post, 60);
                $res = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if ($ent=="com") {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],
                        ["datablock" => "Performance", "level" => "2"],
                        ["datablock" => "BusinessNetwork", "level" => "2"],
                        ["datablock" => "Unfavorable", "level" => "2"],
                        ["datablock" => "Credit", "level" => "2"],
                        ["datablock" => "SwornAudit", "level" => "2"],
                        ["datablock" => "Events", "level" => "2"],
                        ["datablock" => "Market", "level" => "2"],
                        ["datablock" => "Money", "level" => "2"],
                        ["datablock" => "Groups", "level" => "2"]
                        // ["datablock" => "News", "level" => "0"],
                    ];
                } else {
                    $tmpl = [
                        ["datablock" => "Basic", "level" => ""],                   
                        ["datablock" => "BusinessNetwork", "level" => "2"],
                        ["datablock" => "Unfavorable", "level" => "2"]
                    ];
                }
                
                $postBody["data"] = array();
                //Make Curl for Lv1 Company profiles data
                foreach ($tmpl as $k2 => $v2) {
                    if (! Cache::has("getCompanyKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt) && ! Cache::has("getPersonKYC" . $v["vat"] . $v2["datablock"] . $v2["level"] . $lang . $environmt)) {
                        // $postBody["data"] = [$v2];
                        array_push($postBody["data"], $v2);
                        $cached .= "[" . $v["vat"] . "-" . $v2["datablock"] . "-" . $v2["level"] . "] ";
                    }
                }
                $post = json_encode($postBody);
                $curl = $this->preparePostCurl(env("APP_URL_inside") . "/api/v1/WebPortal/".$e1."/overview", '', $post, 60);
                $res = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);
            }
        }
        Log::info('KYC Search - Finish Profile Cache for ' . $cached);
        return "ok";
    }

    /**
     * This Method handles the proccess of send order payment confirmation email to the purchaser.
     * Accepts Request $request with order payment details()
     * Returns Array with message about it success or fail
     */
    public function sendOrderPaymentEmail(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        $emailTemplateList = [
            '0' => 'emails.order-bank-payment',
            '1' => 'emails.order-card-payment',
        ];
        $results = ['messages' => [
            'title' => "the email has been send",
            'message_code' => 1
        ]];
        $resStatus = 200;
        //Validate User through Api Token and billing mail(client)
        if (! $helperCont->validateApiToken($request, true))
            exit();
        if (! $helperCont->validateRequestParam($request, 'billing_email'))
            exit();
        $recieverMail = $request['billing_email'];
        //Prepare Data for Email Template
        $mailData = $helperCont->getMailPaymentDataFormatted($request);
        //Set Template
        $emailTemplate = '0';
        if (isset($mailData['isPaid']))
            $emailTemplate = strval($mailData['isPaid']);
        //Send Mail to Client
        try {
            Mail::send($emailTemplateList[$emailTemplate], $mailData, function ($message) use ($recieverMail, $mailData) {
                $message->to($recieverMail)
                    ->subject('Ειδοποίηση - Παραγγελία #' . $mailData['salesOrderNumber']);
                $message->from('cs@lbsuite.eu', 'Linked Business');
            });

        } catch (Exception $e) {
            Log::error('There was an error trying to send order payment email to ' . $request['billing_email'] . " : " . $e->getMessage());
            $results = ['messages' => [
                'title' => "the email restrictions are not met",
                'message_code' => 2
            ]];
            $resStatus = 429;
        }
        //return response    
        return response()->json($results, $resStatus);
    }

    /**
     * This Method handles the trigger for checking pending B2B Sales Lead List
     * Accepts Request $request with api token
     * Returns Array with message about it success or fail
     */
    public function handleB2BListCheck(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        $results = ['messages' => [
            'title' => "The trigger fired off successfully",
            'message_code' => 0
        ]];
        $resStatus = 200;
        //Validate User through Api Token 
        if (! $helperCont->validateApiToken($request))
            exit();
        //Trigger Check for pending orders
        try {
            $helperCont->handleB2BListCheck();
        } catch (Exception $e) {
            Log::error('There was an error trying to trigger check for B2B Sales Lead Lists Orders: ' . $e->getMessage());
            $results = ['messages' => [
                'title' => "The trigger did not fired off",
                'message_code' => 1
            ]];
            $resStatus = 501;
        }
        //return response    
        return response()->json($results, $resStatus);
    }

    public function redeemedVats(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        if (! $helperCont->validateApiToken($request))
            exit();
        //Check if has market stats and retrieve
        $vats = DB::table('vat_credit')->pluck('vat');

        return response()->json($vats, 200);


        

    }

    public function updateKYCCache(Request $request)
    {
        $helperCont = (new WebPortalHelperControllerV1);
        Log::info('KYC Profiles Recache - START');
        $token = $this->bearerToken();
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        if (Cache::has("updateKYCCache".env("APP_Env"))) {
            Log::info('KYC Profiles Recache - cancelled - already running');
            $ret = 'No Profiles updated';
        } else {
            Cache::put("updateKYCCache".env("APP_Env"), "running", 180);
            $ret = $this->updateKYCCacheHelper($request["api_token"]);
            Cache::forget("updateKYCCache".env("APP_Env"));
        }
        Log::info('KYC Profiles Recache - END');
        return $ret;
    }

    public function updateKYCCacheHelper($api_token = "")
    {
        $helperPCont = (new WebPortalProfileController);
        $token = $this->bearerToken();        
        $actions_total = env("RecacheActions");
        // companies with cache problems to pause for some time
        if (Cache::has("updateKYCCache-Problem".env("APP_Env"))) {
            $problem = Cache::get("updateKYCCache-Problem".env("APP_Env"));
        } else {
            $problem = array();
        }
        //Start Clean Up process for cached vats that extends limit
        $helperPCont->cleanUpCachedCompanies();
        //Get cached vats that going to expired
        $data = DB::select("SELECT * FROM KYCtoCache WHERE forever=false AND cacheTo<=CURDATE()");
        Log::info('KYC Profiles Recache - Chunks In Need Of Recache or Delete: ' . count($data));
        
        //get vats in cache
        $vatRecords = json_decode(json_encode(DB::table('cached_companies')->where('keep', 0)->get()), true);
        
        $vatarray = array();
        foreach($vatRecords as $k => $v) {
            array_push($vatarray,$v["vat"]);
        }

        $vun = array();
        foreach($data as $k=>$v) {
            array_push($vun,$v->vat);
        }
        $vun = array_unique($vun);
        Log::info('KYC Profiles Recache - Vats In Need Of Recache or Delete: ' . count($vun));
                
        // Re-cache those vats        
        $count=0;
        $vatused = array();
        $bypassed = array();
        foreach ($data as $d) {                                   
            if ($count>=$actions_total) {
                Log::info('KYC Profiles Recache - Stopped after number of actions: '.$count);
                break;
            }
            $count++; 
            if (!in_array($d->vat,$vatarray)) {
                if (!in_array($d->vat,$vatused)) {
                    sleep(1); 
                    DB::table('KYCtoCache')->where('vat', $d->vat)->delete();
                    Log::info('KYC Profiles Recache - Deleted vat from KYCtoCache table: '.$d->vat);
                    array_push($vatused,$d->vat);
                } 
            } elseif ($d->datablock!="Summary" && !in_array($d->vat,$vatused)) {
                $vat = $d->vat;   
                if (in_array($vat,$problem)) {
                    $count--;  
                    if (!in_array($vat,$bypassed)) {                  
                        array_push($bypassed,$vat);
                    }
                    continue;
                }             
                array_push($vatused,$vat);                
                $dataVat = DB::select("SELECT * FROM KYCtoCache WHERE vat='".$vat."' AND forever=false AND cacheTo<=CURDATE() AND datablock<>'Summary'");
                $s=array();
                $s[0]="";
                $s[1]="";
                $s[2]="";
                foreach($dataVat as $kdv=>$vdv) {
                    if ($vdv->level==0 || $vdv->level==null) {
                        $s[0] = $s[0] . '{ "datablock":"' . $vdv->datablock . '", "level": 0},';
                    } elseif ($vdv->level==1 ) {
                        $s[1]= $s[1] . '{ "datablock":"' . $vdv->datablock . '", "level": 1},';
                    } elseif ($vdv->level==2 ) {
                        $s[2] = $s[2] . '{ "datablock":"' . $vdv->datablock . '", "level": 2},';
                    }
                }
                Log::info('KYC Profiles Recache - Recache vat: ' . $vat . ' for ' . $s[0] . $s[1] . $s[2]);
                $isproblem = false;
                foreach($s as $ks=>$vs) {
                    if ($vs!="") {
                        sleep(1); 
                        $sdbl = substr($vs,0,-1);
                        $post = '{"api_token": "' . $api_token . '",  "data": 
                        ['.$sdbl.'], "vat": "' . $vat . '", "ttl": "extended", "update": true, "cache": "disable", "user_email":"update@KYCCache.com", "user_role":"Guest","catchCacheRecord": false}';
                        $ent = $this->EntityCurl($vat, $token);
                        $jent = json_decode($ent,true);
                        if (isset($jent["isCompany"]) && $jent["isCompany"]) {
                            $tent ="company";
                        } else {
                            $tent ="person";
                        }            
                        //Default update for Greek version
                        $ch = $this->preparePostCurl(config("keycloak.WPD_URL") . "/api/v1/WebPortal/".$tent."/overview", '', $post, 120);
                        $d = curl_exec($ch);
                        $err = curl_error($ch);
                        $httpcodeel = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if (intval($httpcodeel)!=200) {                            
                            $isproblem = true;
                        }
                        
                        //Update for English version
                        sleep(1); 
                        $post = '{"api_token": "' . $api_token . '",  "data": 
                            ['.$sdbl.'], "vat": "' . $vat . '", "ttl": "extended", "update": true, "cache": "disable", "user_email":"update@KYCCache.com", "user_role":"Guest","catchCacheRecord": false, "lang": "en"}';                        
                        $ch = $this->preparePostCurl(config("keycloak.WPD_URL") . "/api/v1/WebPortal/".$tent."/overview", '', $post, 120);
                        $d = curl_exec($ch);
                        $err = curl_error($ch);
                        $httpcodeen = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if (intval($httpcodeen)!=200) {                            
                            $isproblem = true;
                        }

                        
                    }
                }
                if ($isproblem) {
                    $count--;
                    array_push($problem,$vat);
                    Cache::put("updateKYCCache-Problem".env("APP_Env"), array_unique($problem), 300);
                    Log::info('KYC Profiles Recache - Added to the pool of problematic vats: '.$vat);
                }
                Log::info('KYC Profiles Recache - Completion Ratio: ' . (($count/$actions_total)*100)."%");
            }            
        }
        Log::info('KYC Profiles Recache - Completion Ratio: ' . (($count/$actions_total)*100)."%");
        Log::info('KYC Profiles Recache - Bypassed problematic vats: '.json_encode($bypassed));
        return 'Profiles updated successfully';
    }
    /**
     * 
     * This method handle the request to update A list of Company Profiles for certain data block & level
     * Accepts  $request(Request) with $vat(String), $data(Array) with items ["datablock => "DataBlock Name", "level" => X], $api_token(String)
     * Returns $results(String) an echoed message with status
     */
    public function CompanyProfileUpdate(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        $resStatus = 200;

        //Create DB record for call in DB
        try {
            $idc1 = DB::table('webportal_calls')
                ->insertGetId(
                    array('date' => date("Y-m-d H:i:s"),
                        'service' => 'CompanyProfile',
                        'request' => json_encode(
                            $request->all()),
                        'completed' => false)
                );
        } catch (Exception $e) {
            Log::error("Error on WebPortal Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        //Validate Request Parameters
        if (! $helperCont->validateRequestParams($request, ['vat_list', 'data'], 'webportal_calls', $idc1))
            exit();

        $taskid = 0;
        $now = DateTime::createFromFormat('U.u', microtime(true));
        $taskid = $now->format("m-d-Y H:i:s.u");

        $c = array();
        $c["date_init"] = date("Y-m-d H:i:s");
        $c["date_end"] = null;
        $c["task_id"] = $taskid;
        $c["status"] = "running";
        $c["messages"][0]["title"] = "The Update Trigger fired off successfully";
        $c["messages"][1]["message_code"] = 1;
        $c["update_details"] = array();
        $c["errors"] = array();
        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));

        $postBody["api_token"] = env("WPD_API_TOKEN_STAGING_and_PROD");
        $postBody["taskid"] = $taskid;
        $postBody["vat_list"] = $request["vat_list"];
        $postBody["data"] = $request["data"];
        $postBody["init_request"] = $request->all();
        
        $curlWPD = $this->preparePostCurl(env("APP_URL_inside") . "/api/v1/WebPortal/company/updatehelper", '', json_encode($postBody), 2);
        $res = curl_exec($curlWPD);
        $err = curl_error($curlWPD);
        curl_close($curlWPD);
        
        return response()->json($c, $resStatus);

    }

    public function CompanyProfileUpdateHelper(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        $resStatus = 200;
        $hasError = false;
        $badResult = [
            'messages' => [
                [
                    'title' => "The Update Trigger did not fired off",
                    'message_code' => 2
                ]
            ]
        ];
        $succefullResults = [
            'messages' => [
                [
                    'title' => "The Update Trigger fired off successfully",
                    'message_code' => 1
                ]
            ]
        ];
        //Create DB record for call in DB
        try {
            $idc1 = DB::table('webportal_calls')
                ->insertGetId(
                    array('date' => date("Y-m-d H:i:s"),
                        'service' => 'CompanyProfile',
                        'request' => json_encode(
                            $request->all()),
                        'completed' => false)
                );
        } catch (Exception $e) {
            Log::error("Error on WebPortal Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        //Validate Request Parameters
        if (! $helperCont->validateRequestParams($request, ['vat_list', 'data', 'taskid'], 'webportal_calls', $idc1))
            exit();

        // validate same number of updates
        $existcall = DB::table('webportal_calls')->where('request', json_encode($request['init_request']))->where('date', '>=', date("Y-m-d"))->get();
        if (count($existcall) > 20) {
            $c["date_end"] = date("Y-m-d H:i:s");
            $c["status"] = "stopped";
            $c["messages"][0]["title"] = "The Update Trigger did NOT fired off successfully";
            $c["messages"][1]["message_code"] = 2;
            $c["errors"] = "update request already sent 20 times on " . date("Y-m-d");
            Cache::put("updatecachetaskid" . $request["taskid"], $c, config("keycloak.KYCCacheTaskIdTime"));
            return response()->json($c, $resStatus);
        }

        $c = Cache::get("updatecachetaskid" . $request["taskid"]);
        $taskid = $request["taskid"];
        //Set Default post body
        $postBody["api_token"] = env("WPD_API_TOKEN_STAGING_and_PROD");
        $postBody["user_email"] = "dev@lbsuite.eu";
        $postBody["user_role"] = "dev";
        $postBody["update"] = true; // Update Cache record TTLs only 
        $postBody["cache"] = "disable"; // Clear Redis for the vat
        $postBody["ttl"] = "extended"; // Extented curl timeout
        $postBody["catchCacheRecord"] = false; // Prevent update Last_searched for not viewing profile

        try {
            //Trigger WPD /overview 
            if (count($request['vat_list']) > 0) {
                //For each given vat

                foreach ($request['vat_list'] as $k1 => $vat) {
                    $token = $this->bearerToken();
                    $entity = $this->EntityCurl($vat, $token);
                    array_push($c["update_details"], array("vat" => $vat, "entity" => $entity));
                    Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));

                    $dentity = json_decode($entity,true);

                    $postBody_0ens = $postBody;
                    $postBody_0ens["data"] = array();
                    $postBody_0ens["lang"] = 'en';
                    $postBody_0ens["vat"] = $vat;

                    $postBody_1ens = $postBody;
                    $postBody_1ens["data"] = array();
                    $postBody_1ens["lang"] = 'en';
                    $postBody_1ens["vat"] = $vat;

                    $postBody_2ens = $postBody;
                    $postBody_2ens["data"] = array();
                    $postBody_2ens["lang"] = 'en';
                    $postBody_2ens["vat"] = $vat;

                    $postBody_0els = $postBody;
                    $postBody_0els["data"] = array();
                    $postBody_0els["lang"] = 'el';
                    $postBody_0els["vat"] = $vat;

                    $postBody_1els = $postBody;
                    $postBody_1els["data"] = array();
                    $postBody_1els["lang"] = 'el';
                    $postBody_1els["vat"] = $vat;

                    $postBody_2els = $postBody;
                    $postBody_2els["data"] = array();
                    $postBody_2els["lang"] = 'el';
                    $postBody_2els["vat"] = $vat;

                    $postBody_0enp = $postBody;
                    $postBody_0enp["data"] = array();
                    $postBody_0enp["lang"] = 'en';
                    $postBody_0enp["vat"] = $vat;

                    $postBody_1enp = $postBody;
                    $postBody_1enp["data"] = array();
                    $postBody_1enp["lang"] = 'en';
                    $postBody_1enp["vat"] = $vat;

                    $postBody_2enp = $postBody;
                    $postBody_2enp["data"] = array();
                    $postBody_2enp["lang"] = 'en';
                    $postBody_2enp["vat"] = $vat;

                    $postBody_0elp = $postBody;
                    $postBody_0elp["data"] = array();
                    $postBody_0elp["lang"] = 'el';
                    $postBody_0elp["vat"] = $vat;

                    $postBody_1elp = $postBody;
                    $postBody_1elp["data"] = array();
                    $postBody_1elp["lang"] = 'el';
                    $postBody_1elp["vat"] = $vat;

                    $postBody_2elp = $postBody;
                    $postBody_2elp["data"] = array();
                    $postBody_2elp["lang"] = 'el';
                    $postBody_2elp["vat"] = $vat;

                    foreach ($request['data'] as $k2 => $v) {
                        //Don't trigger false datablock
                        if (isset($v["datablock"]) && isset($v["level"])) {
                            //Update only already cached vats
                            if ($v["datablock"] == "Basic")
                                $v["level"] = "";

                            if ($v["level"] == 0 || $v["level"] == "") {
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging")) {
                                    array_push($postBody_0ens["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging")) {
                                    array_push($postBody_0els["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enprod")) {
                                    array_push($postBody_0enp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elprod")) {
                                    array_push($postBody_0elp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                            } elseif ($v["level"] == 1) {
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging")) {
                                    array_push($postBody_1ens["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging")) {
                                    array_push($postBody_1els["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enprod")) {
                                    array_push($postBody_1enp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elprod")) {
                                    array_push($postBody_1elp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                            } elseif ($v["level"] == 2) {
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging")) {
                                    array_push($postBody_2ens["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging")) {
                                    array_push($postBody_2els["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enprod")) {
                                    array_push($postBody_2enp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if (Cache::has("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elprod")) {
                                    array_push($postBody_2elp["data"], array("datablock" => $v["datablock"], "level" => $v["level"]));
                                }
                                if ($v["datablock"] == "BusinessNetwork") {
                                    if (intval($v["level"]) == 2) {
                                        //cyto update from staging updates only staging
                                        if (env("APP_Env")=="prod") {
                                            $postdel = json_encode(array("api_token" => env("LB_API_TOKEN_STAGING_and_PROD"),                                                                   
                                                                        "vatid" => $vat,
                                                                        "cache_delete" => true,                                                                    
                                                                        "level" => 1,
                                                                        "data" => "cytoOwnManDataV2-1"
                                                                    ));
                                            $curlcytodel = $this->preparePostCurl(env("LBAPI_URL_prod") . "/api/v1/companyOther", '', $postdel, 600);
                                            $cyto = curl_exec($curlcytodel);
                                            curl_close($curlcytodel);   
                                            
                                            array_push($c["update_details"], array("vat" => $vat, "data" => array("datablock" => "cytoscape LB Api delete cache", "type" => json_decode($cyto,true), "level" => "all"), "lang" => "el-en", "env" => "prod"));
                                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                        }

                                        $postdel = json_encode(array("api_token" => env("LB_API_TOKEN_STAGING_and_PROD"),                                                                   
                                                                    "vatid" => $vat,
                                                                    "cache_delete" => true,
                                                                    "level" => 1,
                                                                    "data" => "cytoOwnManDataV2-1"
                                                                ));
                                        $curlcytodel = $this->preparePostCurl(env("LBAPI_URL_staging") . "/api/v1/companyOther", '', $postdel, 600);
                                        $cyto = curl_exec($curlcytodel);                                       
                                        curl_close($curlcytodel);                                          
                                        
                                        array_push($c["update_details"], array("vat" => $vat, "data" => array("datablock" => "cytoscape LB Api delete cache", "type" => json_decode($cyto,true), "level" => "all"), "lang" => "el-en", "env" => "staging"));
                                        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));

                                        $lc = array(0, 1, 2, 3);
                                        $tc = array("cytoOwnManDataV2-1");
                                        $lac = array("el", "en");
                                        if (env("APP_Env")=="prod") {
                                            $ec = array("staging", "prod");
                                        } else {
                                            $ec = array("staging");
                                        }
                                        foreach ($lc as $vc1) {
                                            foreach ($tc as $vc2) {
                                                foreach ($lac as $vc3) {
                                                    foreach ($ec as $vc4) {
                                                        if (Cache::has("cytodatap" . $vat . $vc1 . $vc2 . $vc3 . $vc4)) {
                                                            $post = json_encode(array("api_token" => env("LB_API_TOKEN_STAGING_and_PROD"),
                                                                    "data" => $vc2,
                                                                    "vatid" => $vat,
                                                                    "level" => $vc1,
                                                                    // "cache_disable" => true,
                                                                    "lang" => $vc3
                                                            ));
                                                            $curlcyto = $this->preparePostCurl(env("LBAPI_URL_" . $vc4) . "/api/v1/companyOther", '', $post, 600);
                                                            $cyto = curl_exec($curlcyto);
                                                            
                                                            if (!curl_error($curlcyto)) {
                                                                $nodes = json_decode($cyto,true)[0];
                                                                foreach($nodes as $nodek=>$nodev) {
                                                                    if (Cache::has("cytodatap" . substr($nodev["id"],1) . "1" . $vc2 . $vc3 . $vc4)) {
                                                                        Cache::forget("cytodatap" . substr($nodev["id"],1) . "1" . $vc2 . $vc3 . $vc4);
                                                                        array_push($c["update_details"], array("vat" => substr($nodev["id"],1), "data" => array("datablock" => "cytoscape WPD", "type" => $vc2, "level" => 1), "lang" => $vc3, "env" => $vc4));
                                                                        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                                                    }
                                                                    if (Cache::has("cytodatap" . substr($nodev["id"],1) . "2" . $vc2 . $vc3 . $vc4)) {
                                                                        Cache::forget("cytodatap" . substr($nodev["id"],1) . "1" . $vc2 . $vc3 . $vc4);
                                                                        array_push($c["update_details"], array("vat" => substr($nodev["id"],1), "data" => array("datablock" => "cytoscape WPD", "type" => $vc2, "level" => 2), "lang" => $vc3, "env" => $vc4));
                                                                        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                                                    }
                                                                    if (Cache::has("cytodatap" . substr($nodev["id"],1) . "3" . $vc2 . $vc3 . $vc4)) {
                                                                        Cache::forget("cytodatap" . substr($nodev["id"],1) . "1" . $vc2 . $vc3 . $vc4);
                                                                        array_push($c["update_details"], array("vat" => substr($nodev["id"],1), "data" => array("datablock" => "cytoscape WPD", "type" => $vc2, "level" => 3), "lang" => $vc3, "env" => $vc4));
                                                                        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                                                    }
                                                                }
                                                            }
                                                            curl_close($curlcyto);
                                                            // if (Cache::has("cytodatap" . $vat . $vc1 . $vc2 . $vc3 . $vc4)) {    
                                                            Cache::forget("cytodatap" . $vat . $vc1 . $vc2 . $vc3 . $vc4);
                                                            array_push($c["update_details"], array("vat" => $vat, "data" => array("datablock" => "cytoscape LB Api and WPD", "type" => $vc2, "level" => $vc1), "lang" => $vc3, "env" => $vc4));
                                                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                                        } 
                                                        // else {
                                                        //     array_push($c["update_details"], array("vat" => $vat, "data" => array("datablock" => "cytoscape LB Api only", "type" => $vc2, "level" => $vc1), "lang" => $vc3, "env" => $vc4));
                                                        //     Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                                                        // }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            Cache::forget("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging");
                            Cache::forget("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging");
                            Cache::forget("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "enprod");
                            Cache::forget("getCompanyKYC" . $vat . $v["datablock"] . $v["level"] . "elprod");
                            Cache::forget("getCompanyKYC" . $vat . "summaryenstaging");
                            Cache::forget("getCompanyKYC" . $vat . "summaryelstaging");
                            Cache::forget("getCompanyKYC" . $vat . "summaryenprod");
                            Cache::forget("getCompanyKYC" . $vat . "summaryelprod");

                            //only delete cache for person profiles
                            if (Cache::has("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging")) {
                                Cache::forget("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "enstaging");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => $v["datablock"], "level" => $v["level"]), "lang" => "en", "env" => "staging", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging")) {
                                Cache::forget("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "elstaging");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => $v["datablock"], "level" => $v["level"]), "lang" => "el", "env" => "staging", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "enprod")) {
                                Cache::forget("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "enprod");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => $v["datablock"], "level" => $v["level"]), "lang" => "en", "env" => "prod", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "elprod")) {
                                Cache::forget("getPersonKYC" . $vat . $v["datablock"] . $v["level"] . "elprod");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => $v["datablock"], "level" => $v["level"]), "lang" => "el", "env" => "prod", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . "summaryenstaging")) {
                                Cache::forget("getPersonKYC" . $vat . "summaryenstaging");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => "summary", "level" => null), "lang" => "en", "env" => "staging", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . "summaryelstaging")) {
                                Cache::forget("getPersonKYC" . $vat . "summaryelstaging");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => "summary", "level" => null), "lang" => "el", "env" => "staging", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . "summaryenprod")) {
                                Cache::forget("getPersonKYC" . $vat . "summaryenprod");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => "summary", "level" => null), "lang" => "en", "env" => "prod", "details" => "delete cache only"));
                            }
                            if (Cache::has("getPersonKYC" . $vat . "summaryelprod")) {
                                Cache::forget("getPersonKYC" . $vat . "summaryelprod");
                                array_push($c["update_details"], array("vat" => $vat, "datablock" => array("datablock" => "summary", "level" => null), "lang" => "el", "env" => "prod", "details" => "delete cache only"));
                            }
                        }
                    }
                   
                    
                    if ($dentity["isCompany"]==true) {                   

                        if (count($postBody_0ens["data"]) > 0) {
                            $post = json_encode($postBody_0ens);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "datablock" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "datablock" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_1ens["data"]) > 0) {
                            $post = json_encode($postBody_1ens);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_2ens["data"]) > 0) {
                            $post = json_encode($postBody_2ens);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_0els["data"]) > 0) {
                            $post = json_encode($postBody_0els);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_1els["data"]) > 0) {
                            $post = json_encode($postBody_1els);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_2els["data"]) > 0) {
                            $post = json_encode($postBody_2els);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "staging", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_0enp["data"]) > 0) {
                            $post = json_encode($postBody_0enp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_1enp["data"]) > 0) {
                            $post = json_encode($postBody_1enp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_2enp["data"]) > 0) {
                            $post = json_encode($postBody_2enp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_0elp["data"]) > 0) {
                            $post = json_encode($postBody_0elp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_1elp["data"]) > 0) {
                            $post = json_encode($postBody_1elp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                        if (count($postBody_2elp["data"]) > 0) {
                            $post = json_encode($postBody_2elp);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/overview", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production"));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => json_decode($post, true)["data"], "lang" => json_decode($post, true)["lang"], "env" => "production", "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                    }

                    $dpacks = ["Asset Tracer", "KYC Insights", "Pro", "Pro 5", "Pro 5 ", "Master"];
                    foreach ($dpacks as $vp) {
                        $fileName = "KYC-Pro-report-" . $vat . "-staging" . $vp . ".pdf";
                        $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                        $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                        if ($reportFile) {
                            $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                            $postBodyrep = array();
                            $postBodyrep["api_token"] = env("WPD_API_TOKEN_STAGING_and_PROD");
                            $postBodyrep["user_email"] = "dev@lbsuite.eu";
                            $postBodyrep["user_role"] = "dev";
                            $postBodyrep["vat"] = $vat;
                            $postBodyrep["package"] = $vp;
                            $post = json_encode($postBodyrep);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_staging") . "/api/v1/WebPortal/company/report", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => "pdf report", "lang" => "gr", "env" => "staging", "package" => $vp));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => "pdf report", "lang" => "gr", "env" => "prod", "package" => $vp, "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }

                        $fileName = "KYC-Pro-report-" . $vat . "-prod" . $vp . ".pdf";
                        $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                        $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                        if ($reportFile) {
                            $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                            $postBodyrep = array();
                            $postBodyrep["api_token"] = env("WPD_API_TOKEN_STAGING_and_PROD");
                            $postBodyrep["user_email"] = "dev@lbsuite.eu";
                            $postBodyrep["user_role"] = "dev";
                            $postBodyrep["vat"] = $vat;
                            $postBodyrep["package"] = $vp;
                            $post = json_encode($postBodyrep);
                            $curlWPD = $this->preparePostCurl(env("APP_URL_prod") . "/api/v1/WebPortal/company/report", '', $post, 180);
                            $res = curl_exec($curlWPD);
                            $err = curl_error($curlWPD);
                            curl_close($curlWPD);
                            array_push($c["update_details"], array("vat" => $vat, "data" => "pdf report", "lang" => "gr", "env" => "prod", "package" => $vp));
                            if ($err)
                                array_push($c["errors"], array("vat" => $vat, "data" => "pdf report", "lang" => "gr", "env" => "prod", "package" => $vp, "error" => $err));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }

                        $fileName = "KYC-Pro-report-" . $vat . $vp . ".pdf";
                        $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                        $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                        if ($reportFile) {
                            $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                            array_push($c["update_details"], array("vat" => $vat, "data" => "delete old pdf report", "lang" => "gr", "env" => "prod/staging"));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }

                        $fileName = "KYC-Pro-report-" . $vat . ".pdf";
                        $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
                        $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
                        if ($reportFile) {
                            $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
                            array_push($c["update_details"], array("vat" => $vat, "data" => "delete old pdf report", "lang" => "gr", "env" => "prod/staging"));
                            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
                        }
                    }

                }
            }
        } catch (Exception $e) {
            Log::error('KYC Profiles - Failed to trigger update cache for all vats. ' . $e->getMessage());
            $hasError = true;
            array_push($c["errors"], array("message" => 'KYC Profiles - Failed to trigger update cache for all vats. ' . $e->getMessage()));
            Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
        }
        //Update DB call status record
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Profiles');
        //Set&Return response
        $results = ($hasError) ? $badResult : $succefullResults;
        $c["status"] = "completed";
        $c["date_end"] = date("Y-m-d H:i:s");
        Cache::put("updatecachetaskid" . $taskid, $c, config("keycloak.KYCCacheTaskIdTime"));
        return response()->json($results, $resStatus);
    }

    private function EntityCurl($vat, $token)
    {
        $curl_legal_entity = curl_init();
        curl_setopt_array($curl_legal_entity, [
        CURLOPT_PORT => "",
        CURLOPT_URL => config('keycloak.BO_isLegalEntity') . "?vat=" . $vat,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Authorization:Bearer ' . $token,
        ],
        ]);
        $response = curl_exec($curl_legal_entity);
        return $response;
    }


    public function CompanyProfileRedeem(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        $usersContr = (new UsersController);
        $boffCont = (new BackofficeController);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        $environment = config('keycloak.APP_ENVIROMENT');
        ini_set('max_execution_time', 720000);
        header('Content-type: application/json');
        $resStatus = 200;
        $hasError = false;
        $res = null;

        //Create DB record for call in DB
        try {
            $idc1 = DB::table('webportal_calls')
                ->insertGetId(
                    array('date' => date("Y-m-d H:i:s"),
                        'service' => 'CompanyRedeem',
                        'request' => json_encode(
                            $request->all()),
                        'completed' => false)
                );
        } catch (Exception $e) {
            if ($environment === "prod") {
                $status = $usersContr->sendDemandFailEmail($request->vat, $request->user_email);
            }
            Log::error("Error on WebPortal Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request)) {
            if ($environment === "prod") {
                $status = $usersContr->sendDemandFailEmail($request->vat, $request->user_email);
            }
            exit();
        }
        //Validate Request Parameters
        if (! $helperCont->validateRequestParams($request, ['vat', 'user_email', 'user_role'])) {
            if ($environment === "prod") {
                $status = $usersContr->sendDemandFailEmail($request->vat, $request->user_email);
            }
            exit();
        }

        $res = $helperCont->updateCreditVatAndUser($request["vat"], $request["user_email"]);
        $curl = curl_init();
        if (isset($request->lang)) {
            $lang = $request->lang;
        } else {
            $lang = "el";
        }
        if (isset($request->orderId) && isset($request->new_order)) {
            $orderId = $request->orderId;
            $new_order = false;
        } else {
            $orderId = uniqid("o_");
            $new_order = true;
        }
        $postDemand = (object) [
            'api_token' => $request->api_token,
            'vat' => $request->vat,
            'orderId' => $orderId,
            'new_order' => $new_order,
            'lang' => $lang,
            'email' => $request->user_email,
            'user_name' => $request->user_name,
            'productId' => $request->productId,
        ];
        curl_setopt_array($curl, array(
            CURLOPT_URL => config("keycloak.BackofficeWPDDemand"),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($postDemand),
            CURLOPT_HTTPHEADER => array(
                "accept: */*",
                "Content-Type: application/json"
            ),
        ));
        $resp = curl_exec($curl);
        // $err = curl_error($curl);
        // if ($err) {
        //     echo 'Failed demand call to backoffice';
        //     echo $err;
        // }
        curl_close($curl);
        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'KYC Redeem');
        echo json_encode($res);
        exit();
    }

    public function CompanyProfileDeliver(Request $request)
    {
        $usersContr = (new UsersController);
        $email = $request->email;
        $existvat = DB::table('vat_credit')->where('vat', $request["vat"])->get();
        $id = $existvat[0]->id;
        $environment = config('keycloak.APP_ENVIROMENT');
        $api_token = env('WPD_API_TOKEN_STAGING_and_PROD');
        DB::table('users_credit')->where('vat_credit_id', $id)->where('user', $email)->update(array('update_status' => 2));
        $postBody = (object) [
            'api_token' => $api_token,
            'vat' => $request["vat"],
            'user_email' => "deliver@profile.com",
            'user_role' => "Guest",
            'data' => (array) [(object) ["datablock" => "Basic", "level" => 0]],
            'level' => 0
        ];
        $post = json_encode($postBody);
        $curl = $this->preparePostCurl(config('keycloak.WPD_URL') . "/api/v1/WebPortal/company/overview", '', $post);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        if ($err) {
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error','error_code': '10'}]}";
            exit();
        }
        $responseBody = json_decode($response, true);
        $companyName = $responseBody['name'];
        $environment === "staging" ? $domain = 'https://front.linkedbusiness.eu' : $domain = "https://app.linkedbusiness.eu";
        $status = $usersContr->sendCompanyUpdateEmail($companyName, $request["vat"], $domain, $email);
        $post = (object) [
            'api_token' => $api_token,
            'vat_list' => (array) [$request["vat"]],
            'data' => (array) [
                (object) [
                    'datablock' => 'BusinessNetwork',
                    'level' => '2'
                ],
                (object) [
                    'datablock' => 'Performance',
                    'level' => '2'
                ]
            ]
        ];
        $curl_cache = $this->preparePostCurl(config('keycloak.WPD_URL') . '/api/v1/WebPortal/company/update', '', json_encode($post), 60);
        $response = curl_exec($curl_cache);
        $curl_notif = curl_init();
        $postFields = (object) [
            'api_token' => env('Platform_Key'),
            "subject" => 'any',
            "user_email" => $email,
            "vat" => $request["vat"],
            "section" => "any",
            "company_name" => $companyName,
            "company_update" => true,
            "content" => "any"
        ];
        $environment === "staging" ? $domain = 'https://front-api.linkedbusiness.eu' : $domain = "https://app-api.linkedbusiness.eu";
        curl_setopt_array($curl_notif, array(
            CURLOPT_URL => $domain . '/alerts/external',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Cookie: ARRAffinity=6d56f1be202737180efa087c51a16d46323905edad1b701088ec04cd3e5a02eb; ARRAffinitySameSite=6d56f1be202737180efa087c51a16d46323905edad1b701088ec04cd3e5a02eb',
            )
        ));
        $responseNotif = curl_exec($curl_notif);
        return 'done';
    }

    public function CompanyProfileReport(Request $request)
    {
        //Set Helper Controller
        $helperCont = (new WebPortalHelperControllerV1);
        //Set Basic Api call headers
        date_default_timezone_set('Europe/Athens');
        ini_set('max_execution_time', 720000);
        header('Content-type: application/json');
        $resStatus = 200;
        $hasError = false;
        $res = null;
        $lang = (isset($request["lang"]) && $request["lang"] === 'en') ? 'en' : 'el';

        //Create DB record for call in DB
        try {
            $idc1 = DB::table('webportal_calls')
                ->insertGetId(
                    array('date' => date("Y-m-d H:i:s"),
                        'service' => 'CompanyReport',
                        'request' => json_encode(
                            $request->all()),
                        'completed' => false)
                );
        } catch (Exception $e) {
            Log::error("Error on WebPortal Call try to write call in DB" . $e->getMessage());
            http_response_code(500);
            echo "{'errors':[{'title':'Internal Server Error while writing call to WPD DB','error_code': '10'}]}";
            exit();
        }
        //Validate User through Api Token
        if (! $helperCont->validateApiToken($request))
            exit();
        //Validate Request Parameters
        if (! $helperCont->validateRequestParams($request, ['vat', 'user_email', 'user_role']))
            exit();

        $vat = $request["vat"];
        if (isset($request["package"])) {
            $rpack = $request["package"];
        } else {
            $rpack = "Pro";
        }
        // $existvat = DB::table('vat_credit')->where('vat', $request["vat"])->get();
        // if (count($existvat) == 0) {
        //     $r["vat"] = $vat;
        //     $r["status"] = "Not Credited";
        //     $r["file"]["mime"] = "application/zip;base64";
        //     $r["file"]["data"] = null;
        //     http_response_code(200);
        //     echo json_encode($r);
        //     exit();
        // }

        $zip = new ZipArchive();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $rootPath = storage_path() . '\pdfreportfiles\client\\';
            $zip->open($rootPath . '\\' . $vat . $rpack . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        } else {
            $rootPath = storage_path() . '/pdfreportfiles/client';
            $zip->open($rootPath . '/' . $vat . $rpack . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        }

        if (! is_dir($rootPath)) {
            $mypath = $rootPath;
            mkdir($rootPath, 0755, TRUE);
        }
        // $fileName = "KYC-report-" . $vat . ".pdf";
        // $fileName = "KYC-Pro-report-".$request["vat"].".pdf";

        $fileName = "KYC-Pro-report-" . $request["vat"] . "-" . env("APP_Env") . $rpack . $lang . ".pdf";
        $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;

        ///////////always make a new copy of pdf report ////////////////////////
        // $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
        // if ($reportFile) {
        //     $reportFile = Storage::disk('s3-wpd')->delete($s3filepath);
        // }
        ///////////////////////////////////////////////////////////////////////
        $reportFile = Storage::disk('s3-wpd')->exists($s3filepath);
        if ($reportFile) {
            $reportFile = Storage::disk('s3-wpd')->get($s3filepath);
        } else {

            $initjson = json_decode(file_get_contents(base_path() . "/storage/pdfreportfiles/data.json"), true);
            $dat = json_decode($this->pdfReportDataMake($request["vat"], $request["package"], $lang), true);
            foreach ($dat as $k => $v) {
                // if (!isset($dat[$k])) {
                $initjson[$k] = $v;
                // }
            }
            $finaljson = json_encode($initjson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // echo $finaljson;
            // return $finaljson;
            file_put_contents(base_path() . "/storage/pdfreportfiles/data" . $request["vat"] . $rpack . ".json", $finaljson);


            $token = $this->bearerToken();

            $ff1 = new \CurlFile("/" . base_path() . "/storage/pdfreportfiles/data" . $request["vat"] . $rpack . ".json", "application/json", "data.json");
            // $ff2 = new \CurlFile("/".base_path()."/storage/pdfreportfiles/report.css","text/css","report.css");
            // $ff3 = new \CurlFile("/" . base_path() . "/storage/pdfreportfiles/template.html", "text/html", "template.html");
            $ff3 = new \CurlFile("/" . base_path() . "/storage/pdfreportfiles/template_package.html", "text/html", "template.html");
            $ff4 = new \CurlFile("/" . $dat["file1"], "image/png", "chart-events.png");
            $ff5 = new \CurlFile("/" . $dat["file2"], "image/png", "pm1.png");
            $ff6 = new \CurlFile("/" . $dat["file3"], "image/png", "pm2.png");
            $ff7 = new \CurlFile("/" . $dat["file4"], "image/png", "unfav.png");

            for ($i = 0; $i <= 5; $i++) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => config("keycloak.BRpdfreportcustom") . '?vat=' . $request["vat"] . '&lang='.$lang.'&test=true',
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => array('files' => $ff1, 'files2' => $ff7, 'files3' => $ff3, 'files4' => $ff4, 'files5' => $ff5, 'files6' => $ff6),
                    CURLOPT_HTTPHEADER => array(
                        "accept: */*",
                        "Content-Type: multipart/form-data",
                        "Accept-Encoding: gzip, deflate, br",
                        "Connection: keep-alive",
                        'Authorization: Bearer ' . $token
                    ),
                ));
                $rr = curl_exec($curl);
                $response_code_t = json_decode($rr, true);
                curl_close($curl);
                if (isset($response_code_t["application_code"])) {
                    $response_code = $response_code_t["application_code"];
                    break;
                } else {
                    sleep(5);
                }
            }

            for ($i = 0; $i <= 40; $i++) {
                sleep(1);
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => config("keycloak.BR_API_DPDF") . $response_code,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Bearer ' . $token
                    ),
                ));

                $response = curl_exec($curl);
                file_put_contents(base_path() . "/storage/pdfreportfiles/" . $request["vat"] . $rpack . ".pdf", $response);
                if (strlen($response) > 500) {
                    break;
                } else {
                    $rj = json_decode($response, true);
                    if (isset($rj["message"]) && isset($rj["status"])) {
                        if ($rj["message"] == "Report not available for download" && $rj["status"] == "Failed") {
                            return "Error while creating pdf report with code:" . $response_code;
                        }
                    }
                }
                curl_close($curl);
            }

            $pp = file_get_contents(base_path() . "/storage/pdfreportfiles/" . $request["vat"] . $rpack . ".pdf");
            Storage::disk('s3-wpd')->put(config("keycloak.Aws_Folder_WPD_ΒR") . $fileName, $pp);

            $s3filepath = config("keycloak.Aws_Folder_WPD_ΒR") . $fileName;
            $reportFile = Storage::disk('s3-wpd')->get($s3filepath);

        }
        // try {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            file_put_contents($rootPath . '\\' . $fileName, $reportFile);
            $newFileUrl = $rootPath . '\\' . $fileName;
        } else {
            file_put_contents($rootPath . '/' . $fileName, $reportFile);
            $newFileUrl = $rootPath . '/' . $fileName;
        }

        $relativePath = substr($newFileUrl, strlen($rootPath) + 1);
        $zip->addFile($newFileUrl, $relativePath);
        $zip->close();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            $fileZipPath = $rootPath . '\\' . $vat . $rpack . '.zip';
        else
            $fileZipPath = $rootPath . '/' . $vat . $rpack . '.zip';

        $file = file_get_contents($fileZipPath);
        $file64 = base64_encode($file);

        $r["vat"] = $vat;
        $r["status"] = "Completed";
        $r["file"]["mime"] = "application/zip;base64";
        $r["file"]["data"] = $file64;


        http_response_code(200);
        echo json_encode($r);


        if (file_exists(base_path() . "/storage/pdfreportfiles/data" . $request["vat"] . $rpack . ".json"))
            unlink(base_path() . "/storage/pdfreportfiles/data" . $request["vat"] . $rpack . ".json");        
        if (file_exists(base_path() . "/storage/pdfreportfiles/" . $request["vat"] . $rpack . ".pdf"))
            unlink(base_path() . "/storage/pdfreportfiles/" . $request["vat"] . $rpack . ".pdf");
        if (file_exists(base_path() . "/storage/pdfreportfiles/client/" . $request["vat"] . $rpack . ".zip"))
            unlink(base_path() . "/storage/pdfreportfiles/client/" . $request["vat"] . $rpack . ".zip");
        if (file_exists(base_path() . "/storage/pdfreportfiles/client/KYC-Pro-report-" . $request["vat"] . "-staging" . $rpack . $lang . ".pdf"))
            unlink(base_path() . "/storage/pdfreportfiles/client/KYC-Pro-report-" . $request["vat"] . "-staging" . $rpack . $lang . ".pdf");
        if (file_exists(base_path() . "/storage/pdfreportfiles/client/KYC-Pro-report-" . $request["vat"] . "-prod" . $rpack . $lang . ".pdf"))
            unlink(base_path() . "/storage/pdfreportfiles/client/KYC-Pro-report-" . $request["vat"] . "-prod" . $rpack . $lang . ".pdf");
        if (file_exists(base_path() . "/storage/pdfreportfiles/chart-events-" . $request["vat"] . ".png"))
            unlink(base_path() . "/storage/pdfreportfiles/chart-events-" . $request["vat"] . ".png");
        if (file_exists(base_path() . "/storage/pdfreportfiles/pm1-" . $request["vat"] . ".png"))
            unlink(base_path() . "/storage/pdfreportfiles/pm1-" . $request["vat"] . ".png");
        if (file_exists(base_path() . "/storage/pdfreportfiles/pm2-" . $request["vat"] . ".png"))
            unlink(base_path() . "/storage/pdfreportfiles/pm2-" . $request["vat"] . ".png");
        if (file_exists(base_path() . "/storage/pdfreportfiles/unfav-" . $request["vat"] . ".png"))
            unlink(base_path() . "/storage/pdfreportfiles/unfav-" . $request["vat"] . ".png");

        $helperCont->setCallCompleted($idc1, 'webportal_calls', false, 'CompanyReport');

        exit();
    }

    public function getcharturl($type, $data, $lang = "el")
    {
        $token = $this->bearerToken();
        if ($type == "events") {
            $r = array();
            $r["chart_data"] = array();
            $r["chart_data"]["linked_business_category_grouped"] = null;
            $r["chart_data"]["years"] = array();

            if (isset($data["data"]["events"]["announcements_count"]["bi_announcement_changes"][0]) ||
                isset($data["data"]["events"]["announcements_count"]["bI_announcement_exist"][0]) ||
                isset($data["data"]["events"]["announcements_count"]["bi_announcement_manage"][0]) ||
                isset($data["data"]["events"]["announcements_count"]["bi_announcement_ownership"][0]) ||
                isset($data["data"]["events"]["announcements_count"]["bi_announcement_rest"][0]) ||
                isset($data["data"]["events"]["announcements_count"]["bi_announcement_results"][0])
            ) {
                foreach ($data["data"]["events"]["announcements_count"] as $k => $v) {
                    $r["chart_data"]["linked_business_category_grouped"][$k] = array();
                    foreach ($v as $kk => $vv) {
                        if (! in_array($vv["year"], $r["chart_data"]["years"]))
                            array_push($r["chart_data"]["years"], $vv["year"]);
                        if ($vv["count"] == null) {
                            $tmp = "-";
                        } else {
                            $tmp = $vv["count"];
                        }
                        array_push($r["chart_data"]["linked_business_category_grouped"][$k], $tmp);
                    }
                }
            }
            $jdata = json_encode($r);
            $endpoint = config("keycloak.BRcreatechart") . '?chart_type=announcement_counts&lang='.$lang;
            $curl = $this->preparePostCurl($endpoint, $token, $jdata, 120);
            $execute = $this->ExecuteCurl($curl);
            $response = $execute[0];
            
            $path = base_path() . "/storage/pdfreportfiles/chart-events-" . $data["data"]["vat"] . ".png";
            file_put_contents($path, $response);
            return $path;
        } elseif ($type == "pm1") {
            $r = array();
            $r["chart_data"] = array();

            if (isset($data["data"]["money"]["decisionByYear"]["awards"])) {
                $r["chart_data"]["Awards"]["data"] = $data["data"]["money"]["decisionByYear"]["awards"];
            } else {
                $r["chart_data"]["Awards"] = null;
            }
            if (isset($data["data"]["money"]["decisionByYear"]["assignments"])) {
                $r["chart_data"]["Assignments"]["data"] = $data["data"]["money"]["decisionByYear"]["assignments"];
            } else {
                $r["chart_data"]["Assignments"] = null;
            }
            if (isset($data["data"]["money"]["decisionByYear"]["payments"])) {
                $r["chart_data"]["Payments"]["data"] = $data["data"]["money"]["decisionByYear"]["payments"];
            } else {
                $r["chart_data"]["Payments"] = null;
            }
            if (isset($data["data"]["money"]["decisionByYear"]["approvals"])) {
                $r["chart_data"]["Approvals"]["data"] = $data["data"]["money"]["decisionByYear"]["approvals"];
            } else {
                $r["chart_data"]["Approvals"] = null;
            }
            $jdata = str_replace("amount", "value", json_encode($r));

            $endpoint = config("keycloak.BRcreatechart") . '?chart_type=per_year&lang='.$lang;
            $curl = $this->preparePostCurl($endpoint, $token, $jdata, 120);
            $execute = $this->ExecuteCurl($curl);
            $response = $execute[0];

            $path = base_path() . "/storage/pdfreportfiles/pm1-" . $data["data"]["vat"] . ".png";
            file_put_contents($path, $response);
            return $path;
        } elseif ($type == "pm2") {
            $r = array();
            $r["chart_data"] = array();
            $r["chart_data"]["Payments"] = array();
            $r["chart_data"]["Payments"]["perBuyer"] = array();

            if (isset($data["data"]["money"]["largerPayers"])) {
                foreach ($data["data"]["money"]["largerPayers"] as $k => $v) {
                    if ($v["percentage"] != 0 && $v["percentage"] != null) {
                        array_push($r["chart_data"]["Payments"]["perBuyer"], $v);
                    }
                }
            }
           
            $jdata = json_encode($r);
            $endpoint = config("keycloak.BRcreatechart") . '?chart_type=top3_buyers&lang='.$lang;
            $curl = $this->preparePostCurl($endpoint, $token, $jdata, 120);
            $execute = $this->ExecuteCurl($curl);
            $response = $execute[0];

            $path = base_path() . "/storage/pdfreportfiles/pm2-" . $data["data"]["vat"] . ".png";
            file_put_contents($path, $response);
            return $path;
        } elseif ($type == "unfav") {
            $r = array();
            $r["chart_data"] = array();

            if (isset($data["data"]["unfavorable"]["transactional"]["overall"])) {
                $r["chart_data"]["overall"] = $data["data"]["unfavorable"]["transactional"]["overall"];
            }
            if (isset($data["data"]["unfavorable"]["transactional"]["per_years_list"])) {
                $r["chart_data"]["per_year"] = array();
                foreach ($data["data"]["unfavorable"]["transactional"]["per_years_list"] as $k => $v) {
                    $r["chart_data"]["per_year"][$v["year"]]["score"] = $v["score"];
                    $r["chart_data"]["per_year"][$v["year"]]["index"] = $v["ranking"];
                }
            }
            $jdata = json_encode($r);
            $jdata = str_replace("rating", "index", $jdata);

            $endpoint = config("keycloak.BRcreatechart") . '?chart_type=unfavorable&lang='.$lang;
            $curl = $this->preparePostCurl($endpoint, $token, $jdata, 120);
            $execute = $this->ExecuteCurl($curl);
            $response = $execute[0];
           
            $path = base_path() . "/storage/pdfreportfiles/unfav-" . $data["data"]["vat"] . ".png";
            file_put_contents($path, $response);
            return $path;
        }
    }



    public function pdfReportDataMake($vat, $bpack = "Pro", $lang = 'el', $pCont = null)
    {
        if ($pCont === null)  $pCont = (new WebPortalProfileController);
        $dataReq = [
            ["datablock" => "Basic", "level" => "2"],           ["datablock" => "Performance", "level" => "2"],
            ["datablock" => "BusinessNetwork", "level" => "2"], ["datablock" => "Unfavorable", "level" => "2"],
            ["datablock" => "Events", "level" => "2"],          ["datablock" => "News", "level" => "2"],
            ["datablock" => "Money", "level" => "2"],           ["datablock" => "Credit", "level" => "2"],
            ["datablock" => "SwornAudit", "level" => "2"],      ["datablock" => "Market", "level" => "2"]
        ];
        
        $token = $this->bearerToken();
        
        $data = $pCont->getCompanyProfileData($vat, $dataReq, $token, false, false, null, $lang);
        
        // file_put_contents(base_path()."/storage/pdfreportfiles/dataservice".$vat.".json",json_encode($data));

        $dataReq = [
            ["datablock" => "Performance", "level" => "1"], ["datablock" => "Credit", "level" => "1"], 
            ["datablock" => "SwornAudit", "level" => "1"],  ["datablock" => "BusinessNetwork", "level" => "1"], 
            ["datablock" => "Unfavorable", "level" => "1"], ["datablock" => "Market", "level" => "1"]
        ];
       
        $data_short = $pCont->getCompanyProfileData($vat, $dataReq, $token, false, false, null, $lang);
        
        // file_put_contents(base_path()."/storage/pdfreportfiles/dataserviceshort".$vat.".json",json_encode($data_short));

        if ($lang=='el') {
            $months = [
                "01" => "Ιανουάριος",  "02" => "Φεβρουάριος", "03" => "Μάρτιος",   "04" => "Απρίλιος", 
                "05" => "Μάιος",       "06" => "Ιούνιος",     "07" => "Ιούλιος",   "08" => "Αύγουστος", 
                "09" => "Σεπτέμβριος", "10" => "Οκτώβριος",   "11" => "Νοέμβριος", "12" => "Δεκέμβριος"
            ];
        } else {
            $months = [
                "01" => "January",   "02" => "February", "03" => "March",    "04" => "April", 
                "05" => "May",       "06" => "June",     "07" => "July",     "08" => "August",
                "09" => "September", "10" => "October",  "11" => "November", "12" => "December"
            ];
        }
        $fmt = numfmt_create("el_GR", NumberFormatter::DEFAULT_STYLE);
        $fmt2 = numfmt_create("el_GR", NumberFormatter::PERCENT);
        numfmt_set_attribute($fmt2, NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $fmt3 = numfmt_create("el_GR", NumberFormatter::DEFAULT_STYLE);
        numfmt_set_attribute($fmt3, NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $fmt4 = numfmt_create("el_GR", NumberFormatter::DEFAULT_STYLE);
        numfmt_set_attribute($fmt4, NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $fmt5 = numfmt_create("el_GR", NumberFormatter::PERCENT);
        numfmt_set_attribute($fmt5, NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $fmt6 = numfmt_create("el_GR", NumberFormatter::DEFAULT_STYLE);
        numfmt_set_attribute($fmt6, NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $fmt7 = numfmt_create("el_GR", NumberFormatter::DEFAULT_STYLE);
        numfmt_set_attribute($fmt6, NumberFormatter::MAX_FRACTION_DIGITS, 3);
      
        $urlEvents = $this->getcharturl("events", $data, $lang);
        $urlpm1 = $this->getcharturl("pm1", $data, $lang);
        $urlpm2 = $this->getcharturl("pm2", $data, $lang);
        $urlUnfav = $this->getcharturl("unfav", $data, $lang);

        $blocksPack = $this->ChosePack($bpack)[0];
        $oneglancecards = $this->ChosePack($bpack)[1];

        foreach ($data["data"] as $key => $value) {

            switch ($key) {

                case "name": // legal_name
                    $company_name = $value;
                    break;

                case "basic":
                    $foundDate = $value["incorporationDate"]; // company_birth_date
                    $companyActivity = $value["main_section"]; // main_activity
                    $companyAddress = $value["address"]; // address
                    $companyCity = $value["city"]; // city
                    $cityCode = $value["pcode"]; // pc
                    $area = ($value["municipality"] != null) ? $value["municipality"] : "-"; // locality
                    break;

                case "market_lb": // market_trbc
                    $market = $value;
                    break;

                case "vat_status": // vat_status
                    $vatStatus = ($value == true) ? "Ενεργός" : "Ανενεργός";
                    break;

                case "gemh_status": // gemh_status
                    $gemiStatus = ($value == true) ? "Ενεργός" : "Ανενεργός";
                    break;

                case "gemh": // company_number
                    $companyGemi = $value;
                    break;

                case "phone": // phone

                    $companyPhone = $value;

                    break;

                case "website":
                    $website = ($value != null) ? $value : "-";
                    break;

                case "email":
                    $email = ($value != null) ? $value : "-";
                    break;
               
                case "performance": 
                    //summary_performance_table,summary_performance_title,results_title,basic_variables_table,indices_table
                    if ($lang=='el') {
                        $results_title = "Επιδόσεις: Βασικά Μεγέθη";
                     } else {
                        $results_title = "Financial Performance";
                     }
                    $basic_variables_table = array(array());
                    if ($lang=='el') {
                        $basic_variables_table[0][0] = "Βασικά Μεγέθη";
                    } else {
                        $basic_variables_table[0][0] = "Key Economic Variables";
                    }
                    $indices_table = array();
                    if ($lang=='el') {
                        $indices_table[0][0] = "Δείκτες";
                     } else {
                        $indices_table[0][0] = "Financial&nbsp;Ratios";
                     }

                    if ($value != null) {
                        $performance_table = array(array(), array());
                        if ($lang=='el') $performance_table[0][0] = "Πωλήσεις"; else  $performance_table[0][0] = "Turnover";
                        $performance_table[0][1] = numfmt_format($fmt6, $data_short["data"]["performance"]["revenue"]);
                        if ($lang=='el') $performance_table[1][0] = "Κέρδη πΦ"; else $performance_table[1][0] = "EBIT";
                        $performance_table[1][1] = numfmt_format($fmt6, $data_short["data"]["performance"]["ebit"]);
                        // $first_sum = 0;
                        // $second_sum = 0;
                        if ($lang=='el') $performance_title = "Επιδόσεις"; else $performance_title = "Performance";
                        $basic_variables_table[0][1] = null;
                        $basic_variables_table[0][2] = null;
                        $basic_variables_table[0][3] = null;
                        $basic_variables_table[0][4] = null;
                        $basic_variables_table[0][5] = null;
                        $basic_variables_table[0][6] = null;
                        if (isset($value["basicVariables"][0]["data"][0]["year"])) {
                            $basic_variables_table[0][1] = strval($value["basicVariables"][0]["data"][0]["year"]);
                        }
                        if (isset($value["basicVariables"][0]["data"][1]["year"])) {
                            $basic_variables_table[0][2] = strval($value["basicVariables"][0]["data"][1]["year"]);
                        }
                        if (isset($value["basicVariables"][0]["data"][2]["year"])) {
                            $basic_variables_table[0][3] = strval($value["basicVariables"][0]["data"][2]["year"]);
                        }

                        if ($value["basicVariables"] != null)
                            for ($i = 0; $i < count($value["basicVariables"]); $i++) {

                                $array1 = array();
                                $array1[0] = $value["basicVariables"][$i]["name"];
                                $n = 1;
                                for ($o = 0; $o < count($value["basicVariables"][$i]["data"]); $o++) {
                                    if (isset($value["basicVariables"][$i]["data"][$o]["value"])) {
                                        $array1[$n] = numfmt_format($fmt6, $value["basicVariables"][$i]["data"][$o]["value"]);
                                    } else {
                                        $array1[$n] = "-";
                                    }
                                    $n++;
                                    if (isset($value["basicVariables"][$i]["data"][$o]["diff"])) {
                                        $array1[$n] = numfmt_format($fmt6, round($value["basicVariables"][$i]["data"][$o]["diff"] * 100, 2)) . "%";
                                    } else {
                                        $array1[$n] = "-";
                                    }
                                    $n++;
                                }
                                array_push($basic_variables_table, $array1);
                            }

                        if (isset($value["indices"][0]["data"][0]["year"])) {
                            $indices_table[0][1] = strval($value["indices"][0]["data"][0]["year"]);
                        }
                        if (isset($value["basicVariables"][0]["data"][1]["year"])) {
                            $indices_table[0][2] = strval($value["indices"][0]["data"][1]["year"]);
                        }
                        if (isset($value["basicVariables"][0]["data"][2]["year"])) {
                            $indices_table[0][3] = strval($value["indices"][0]["data"][2]["year"]);
                        }

                        if ($value["indices"] != null)
                            for ($i = 0; $i < count($value["indices"]); $i++) {
                                $array1 = array();
                                $array1[0] = $value["indices"][$i]["name"];
                                $n = 1;
                                for ($o = 0; $o < count($value["indices"][$i]["data"]); $o++) {
                                    if (isset($value["indices"][$i]["data"][$o]["value"])) {
                                        $array1[$n] = numfmt_format($fmt7, $value["indices"][$i]["data"][$o]["value"]);
                                    } else {
                                        $array1[$n] = "-";
                                    }
                                    $n++;
                                }
                                array_push($indices_table, $array1);
                            }

                    }
                    $xres = $data["data"]["performance"]["last_updated_basicVariables"];
                    // $performance_table[0][1] = ($first_sum != 0) ? numfmt_format($fmt6, $first_sum) : "-";
                    // $performance_table[1][1] = ($second_sum != 0) ? numfmt_format($fmt6, $second_sum) : "-";
                    break;

                case "money": // summary_public_money_table, summary_public_money_title, public_money_table, public_money_title, public_money_page_footnote

                    if ($lang=='el') {
                        $sum_money_table = array(array(), array());
                        $sum_money_table[0][0] = "Πληρωμές από το Δημόσιο";
                        $sum_money_table[1][0] = "Έργα ΕΣΠΑ 2014-20";
                        $money_table = array(array(), array(), array(), array(), array(), array(), array(), array(), array());
                        $money_table[0][0] = "Δείκτες";
                        $money_table[0][1] = "Τιμές";
                        $money_table[1][0] = "Συνολικό Ποσό Πληρωμών από το Δημόσιο";
                        $money_table[2][0] = "Σύνολο Οικονομικών Αποφάσεων από το Δημόσιο";
                        $money_table[3][0] = "Μεγαλύτερη Κατηγορία Πληρωμών";
                        $money_table[4][0] = "Έτος με τις περισσότερες αποφάσεις";
                        $money_table[5][0] = "Ποσό Αποφάσεων Τρέχοντος Έτους";
                        $money_table[6][0] = "Ποσό Πληρωμών Τρέχοντος Έτους";
                        $money_table[7][0] = "Σύνολο Αποφάσεων Τρέχοντος Έτους";
                        // $money_table[8][0] = "Έργα ΕΣΠΑ 2014-20 (Προϋπολογισμός)";
                        $money_table[8][0] = "Σύνολο Πληρωμών ΕΣΠΑ προς την Εταιρεία";
                        $public_money_page_footnote = "Δίνονται δεδομένα μεταξύ άλλων από: ΕΣΠΑ 2014-2020. Δεδομένα από τη ΔΙΑΥΓΕΙΑ στο χρονικό παράθυρο: 2020-2023.";
                    } else {
                        $sum_money_table = array(array(), array());
                        $sum_money_table[0][0] = "State Payments";
                        $sum_money_table[1][0] = "NSRF Projects 2014-20";
                        $money_table = array(array(), array(), array(), array(), array(), array(), array(), array(), array());
                        $money_table[0][0] = "Indicators";
                        $money_table[0][1] = "Values";
                        $money_table[1][0] = "Total Amount of State Payments";
                        $money_table[2][0] = "Financial Decisions by the State";
                        $money_table[3][0] = "Highest Payment Category";
                        $money_table[4][0] = "Year with the most decisions";
                        $money_table[5][0] = "Amount of Current Year Decisions";
                        $money_table[6][0] = "Amount of Current Year Payments";
                        $money_table[7][0] = "Total Decisions of the Current Year";
                        // $money_table[8][0] = "Έργα ΕΣΠΑ 2014-20 (Προϋπολογισμός)";
                        $money_table[8][0] = "Total NSRF Payments to the Company";
                        $public_money_page_footnote = "Data is provided, among others, from: NSRF 2014-2020. Data from DIAVGEIA in the time window: 2020-2023.";
                    }
                    $public_sum = 0;
                    $decisions_amount = 0;
                    $total_espa = 0;
                    if (isset($data["data"]["money"]["last_updated_publicMoney"])) {
                        $xmon = $data["data"]["money"]["last_updated_publicMoney"];
                    } else {
                        $xmon = null;
                    }

                    if ($value != null) {

                        foreach ($value as $key2 => $money_object) {

                            if ($key2 == "payments") {
                                $sum_money_table[0][1] = ($money_object != 0) ? number_format($money_object, 0, ",", ".") : "-";
                            } elseif ($key2 == "espa") {
                                $sum_money_table[1][1] = ($money_object != 0) ? number_format($money_object, 0, ",", ".") : "-";
                                $espa_projects = number_format($money_object, 0, ",", ".");
                            } elseif ($key2 == "decisionByYear") {
                                $years = array();
                                $amounts_by_year = array();

                                if ($money_object != null) {

                                    for ($j = 0; $j < count($money_object["payments"]); $j++) {
                                        $decisions_amount += $money_object["payments"][$j]["amount"];
                                        array_push($years, $money_object["payments"][$j]["year"]);
                                        array_push($amounts_by_year, $money_object["payments"][$j]["amount"]);
                                    }

                                    $max_year = strval(max($years));
                                    $min_year = strval(min($years));
                                    $max_amount = max($amounts_by_year);

                                    for ($k = 0; $k < count($amounts_by_year); $k++) {

                                        if ($amounts_by_year[$k] == $max_amount) {
                                            $largest_year = $money_object["payments"][$k]["year"];
                                        }
                                    }
                                }
                            } elseif ($key2 == "publicMoney" && $money_object != null) {

                                foreach ($money_object as $public_key => $public_object) {

                                    if ($public_key == "years") {
                                        $current_year = strval($public_object["current_year"]);
                                    } elseif ($public_key == "payments_ammount") {
                                        $public_sum += $public_object["last_three_years"] + $public_object["current_year"];
                                    } elseif ($public_key == "larger_buyers_category") {
                                        $largest_category = $public_object["last_three_years"];
                                    } elseif ($public_key == "awards_assignments_amounts") {
                                        $current_decision_amount = number_format($public_object["current_year"], 0, ",", ".");
                                        $total_espa += $public_object["last_three_years"] + $public_object["current_year"];
                                    } elseif ($public_key == "awards_assignments_count") {
                                        $count_decisions = strval($public_object["current_year"]);
                                    }
                                }
                            }
                        }
                        $decisions_amount = number_format($decisions_amount, 0, ",", ".");
                    }

                    $money_table[1][1] = ($public_sum != 0) ? number_format($public_sum, 0, ",", ".") : "-";
                    $money_table[2][1] = strval($decisions_amount);
                    $money_table[3][1] = (isset($largest_category)) ? $largest_category : "-";
                    $money_table[4][1] = (isset($largest_year)) ? strval($largest_year) : "-";
                    $money_table[5][1] = (isset($current_decision_amount)) ? $current_decision_amount : "0";
                    $money_table[6][1] = "-";
                    $money_table[7][1] = (isset($count_decisions)) ? $count_decisions : "0";
                    $money_table[8][1] = $espa_projects;
                    // $money_table[9][1] = ($total_espa != 0) ? number_format($total_espa, 0, ",", ".") : "- (-)";
                    if ($lang=='el') {
                        $footnote = (isset($max_year)) ? "Δίνονται δεδομένα μεταξύ άλλων από: ΕΣΠΑ 2014-2020. Δεδομένα από τη Διαύγεια στο χρονικό παράθυρο: 2020-" . $max_year . "."
                            : "Δίνονται δεδομένα μεταξύ άλλων από: ΕΣΠΑ 2014-2020. Δεδομένα από τη Διαύγεια στο χρονικό παράθυρο: 2020 ";
                        $sum_money_title = (isset($max_year) && isset($min_year)) ? "Δημόσιο Χρήμα (" . $min_year . "-" . $max_year . ")" : "Δημόσιο Χρήμα (-)";
                        $money_title = (isset($current_year)) ? "Δημόσιο Χρήμα (2014-" . $current_year . ")" : "Δημόσιο Χρήμα (2014-2020)";
                    } else {
                        $footnote = (isset($max_year)) ? "Data are given, among others, from: NSRF 2014-2020. Data from Diavgeia in time window: 2020-" . $max_year . "."
                            : "Data are given, among others, from: NSRF 2014-2020. Data from Diaygeia in the time window: 2020";
                        $sum_money_title = (isset($max_year) && isset($min_year)) ? "Public Money (" . $min_year . "-" . $max_year . ")" : "Public Money (-)";
                        $money_title = (isset($current_year)) ? "Public Money (2014-" . $current_year . ")" : "Public Money (2014-2020)";
                    }
                    break;

                case "unfavorable": // summary_adverse_table, summary_adverse_title, fines, DFTitle, DFTable, AucDetailTable, AucDetailTitle, AucTitle
                    $adverse_table = array(array(), array());
                    // $adverse_title = "Δυσμενή";
                    $fines_list = array();
                    $df_table = array(array(), array(), array(), array());
                    $auctions_table = array(array());
                    // $debtor_sum = 0;
                    $auctions_details_table = array(array(), array(), array(), array());

                    foreach ($value as $key3 => $unfavorable) {

                        if ($key3 == "total_fines") {
                            $total_fines = ($unfavorable != null) ? numfmt_format($fmt6, $unfavorable) : "-";
                        } elseif ($key3 == "fines") {

                            if ($unfavorable != null) {
                                $fines_years = array();
                                $number_of_fines = count($unfavorable);

                                for ($k = 0; $k < $number_of_fines; $k++) {
                                    array_push($fines_years, explode("/", $unfavorable[$k]["date"])[2]);
                                    array_push($fines_list, $unfavorable[$k]);
                                }

                                $max_fine_year = max($fines_years);
                                $min_fine_year = min($fines_years);
                            }
                            $finestable = $fines_list;
                        } elseif ($key3 == "auctions") {
                            
                        }
                    }

                    if ($lang=='el') {
                        $adverse_table[0][0] = "Οφειλές & Πρόστιμα (" . substr($data["data"]["unfavorable"]["last_updated_fines"], -4) . ")";
                    } else {
                        $adverse_table[0][0] = "Debts & Fines (" . substr($data["data"]["unfavorable"]["last_updated_fines"], -4) . ")";
                    }
                    $adverse_table[0][1] = numfmt_format($fmt6, $data["data"]["unfavorable"]["total_fines"] + $data["data"]["unfavorable"]["total_public_efka_debts"]);

                    if ($lang=='el') {
                        $adverse_table[1][0] = "Πλειστηριασμοί (" . substr($data["data"]["unfavorable"]["last_updated_auctions"], -4) . ")";
                    } else {
                        $adverse_table[1][0] = "Auctions (" . substr($data["data"]["unfavorable"]["last_updated_auctions"], -4) . ")";
                    }
                    $adverse_table[1][1] = numfmt_format($fmt6, $data["data"]["unfavorable"]["total_auctions"]);


                    if (isset($data["data"]["unfavorable"]["debts_list"]["EfkaData"][0])) {
                        $total_efka_debts = numfmt_format($fmt6, $data["data"]["unfavorable"]["debts_list"]["EfkaData"][0]["Amount"]);
                    } else {
                        $total_efka_debts = "-";
                    }
                    if (isset($data["data"]["unfavorable"]["debts_list"]["PublicData"][0])) {
                        $total_public_debts = numfmt_format($fmt6, $data["data"]["unfavorable"]["debts_list"]["PublicData"][0]["Amount"]);
                    } else {
                        $total_public_debts = "-";
                    }

                    if ($lang=='el') {
                        $df_table[0][0] = "Οφειλές Δημοσίου";
                        $df_table[1][0] = "Οφειλές ΕΦΚΑ";
                        $df_table[2][0] = "Πρόστιμα";
                        $df_table[3][0] = "Αριθμός Προστίμων";
                    } else {
                        $df_table[0][0] = "Debts to the State";
                        $df_table[1][0] = "Debts to National Insurance Fund";
                        $df_table[2][0] = "Fines";
                        $df_table[3][0] = "Fines Count";
                    }
                    $df_table[0][1] = $total_public_debts;
                    $df_table[1][1] = $total_efka_debts;
                    $df_table[2][1] = $total_fines;
                    $df_table[3][1] = (isset($number_of_fines)) ? $number_of_fines : "-";

                    if ($lang=='el') {                        
                        $dftitle = "Οφειλές & Πρόστιμα";
                        $auctti = "Πλειστηριασμοί";
                        $auctions_table[0][0] = "Πραγματικοί Πλειστηριασμοί";
                    } else {
                        $dftitle = "Debts & Fines";
                        $auctti = "Auctions";
                        $auctions_table[0][0] = "Real Auctions";
                    }
                    if (isset($data["data"]["unfavorable"]["total_auctions"])) {
                        $auctions_table[0][1] = number_format($data["data"]["unfavorable"]["total_auctions"], 0, ",", ".");
                    } else {
                        $auctions_table[0][1] = "-";
                    }

                    if ($lang=='el') {
                        $auc_detail_title = "Πλειστηριασμοί ως Οφειλέτης (3 πιο πρόσφατοι)";
                    } else {
                        $auc_detail_title = "Auctions as Debtor (3 most recent)";
                    }


                    if (isset($data["data"]["unfavorable"]["auctions"]["debtor"][0])) {
                        if ($lang=='el') {
                            $auctions_details_table[0][0] = "Ημερομηνία";
                            $auctions_details_table[0][1] = "Επισπεύδοντες";
                            $auctions_details_table[0][2] = "Ποσό";
                            $auctions_details_table[0][3] = "Κατάσταση";
                            $auctions_details_table[0][4] = "Αξιολόγηση LB";
                            $auctions_details_table[0][5] = "Περιγραφή";
                        } else {
                            $auctions_details_table[0][0] = "Date";
                            $auctions_details_table[0][1] = "Creditors";
                            $auctions_details_table[0][2] = "Amount";
                            $auctions_details_table[0][3] = "Status";
                            $auctions_details_table[0][4] = "LB Evaluation";
                            $auctions_details_table[0][5] = "Description";
                        }
                        foreach ($data["data"]["unfavorable"]["auctions"]["debtor"] as $ka => $va) {
                            $auctions_details_table[$ka + 1][0] = $va["date"];
                            $auctions_details_table[$ka + 1][1] = $va["bidder"];
                            $auctions_details_table[$ka + 1][2] = number_format($va["amount"], 0, ",", ".");
                            $auctions_details_table[$ka + 1][3] = $va["status"];
                            $auctions_details_table[$ka + 1][4] = $va["lb_rating"];
                            $auctions_details_table[$ka + 1][5] = $va["description"];
                            if ($ka >= 2) {
                                break;
                            }
                        }
                    } else {
                        $auctions_details_table = null;
                    }

                    if ($lang=='el') {
                        $auc_detail_title2 = "Πλειστηριασμοί ως Επισπεύδων (3 πιο πρόσφατοι)";
                    } else {
                        $auc_detail_title2 = "Auctions as Creditor (3 most recent)";
                    }


                    if (isset($data["data"]["unfavorable"]["auctions"]["bidder"][0])) {
                        if ($lang=='el') {
                            $auctions_details_table2[0][0] = "Ημερομηνία";
                            $auctions_details_table2[0][1] = "Οφειλέτες";
                            $auctions_details_table2[0][2] = "Ποσό";
                            $auctions_details_table2[0][3] = "Κατάσταση";
                            $auctions_details_table2[0][4] = "Αξιολόγηση LB";
                            $auctions_details_table2[0][5] = "Περιγραφή";
                        } else {
                            $auctions_details_table2[0][0] = "Date";
                            $auctions_details_table2[0][1] = "Debtors";
                            $auctions_details_table2[0][2] = "Amount";
                            $auctions_details_table2[0][3] = "Status";
                            $auctions_details_table2[0][4] = "LB Evaluation";
                            $auctions_details_table2[0][5] = "Description";
                        }
                        foreach ($data["data"]["unfavorable"]["auctions"]["bidder"] as $ka => $va) {
                            $auctions_details_table2[$ka + 1][0] = $va["date"];
                            $auctions_details_table2[$ka + 1][1] = $va["bidder"];
                            $auctions_details_table2[$ka + 1][2] = number_format($va["amount"], 0, ",", ".");
                            $auctions_details_table2[$ka + 1][3] = $va["status"];
                            $auctions_details_table2[$ka + 1][4] = $va["lb_rating"];
                            $auctions_details_table2[$ka + 1][5] = $va["description"];
                            if ($ka >= 2) {
                                break;
                            }
                        }
                    } else {
                        $auctions_details_table2 = null;
                    }

                    if ($auctions_details_table == null && $auctions_details_table2 == null) {
                        if ($lang=='el') { 
                            $auctti = "Πλειστηριασμοί";
                        } else {
                            $auctti = "Auctions";
                        }
                    }

                    if (isset($data_short["data"]["unfavorable"]["last_update"])) {
                        $xunf = $data_short["data"]["unfavorable"]["last_update"];
                    } else {
                        $xunf = null;
                    }

                    if (isset($data["data"]["unfavorable"]["transactional"]["rating"])) {
                        $behavior_assessment_index = $data["data"]["unfavorable"]["transactional"]["rating"];
                    }
                    if (count($behavior_assessment_index) > 0) {
                        $yearbat = 0;
                        foreach ($behavior_assessment_index as $k => $v) {
                            if (isset($v["year"])) {
                                if ($v["year"] > $yearbat) {
                                    $behavior_assessment_index_last_year = $v;
                                    $yearbat = $v["year"];
                                }
                            }
                        }
                        unset($yearbat);
                    } else {
                        $behavior_assessment_index_last_year = null;
                    }
                    break;

                case "swornAudit": // opinions_full

                    if (isset($data["data"]["swornAudit"]["extract"])) {
                        $sworn_audit_text = $data["data"]["swornAudit"]["extract"];
                    } else {
                        $sworn_audit_text = null;
                    }
                    if (isset($data["data"]["swornAudit"]["assessment_index"])) {
                        $assessment_index = $this->sort_rating($data["data"]["swornAudit"]["assessment_index"], "asc");
                    } else {
                        $assessment_index = null;
                    }
                    if (isset($data_short["data"]["swornAudit"]["last_update"])) {
                        $xswo = $data_short["data"]["swornAudit"]["last_update"];
                    } else {
                        $xswo = null;
                    }

                    if (isset($data["data"]["swornAudit"]["remarks"])) {
                        $opinions_summary = $data["data"]["swornAudit"]["remarks"];
                    } else {
                        $opinions_summary = null;
                    }

                    if (isset($data["data"]["swornAudit"]["extract"])) {
                        $opinions_full[0] = $data["data"]["swornAudit"]["extract"];
                    } else {
                        $opinions_full = null;
                    }
                    break;

                case "events": // company_events_list_table, company_events_list_title, demographics
                    $list = array(array(), array(), array(), array(), array(), array());
                    $demographics = array();
                    $x = $data["data"]["events"]["last_updated_announcements_list"];
                    foreach ($value as $key4 => $event) {

                        if ($key4 == "announcements_list") {
                            if ($lang=='el') {
                                $list[0][0] = "Ημερομηνία";
                                $list[0][1] = "Τίτλος Ανακοίνωσης";
                                $list[0][2] = "Κατηγορία LB";
                            } else {
                                $list[0][0] = "Date";
                                $list[0][1] = "Title";
                                $list[0][2] = "Category LB";
                            }

                            for ($m = 0; $m < count($event); $m++) {

                                if ($m < 5) {
                                    $date = array_reverse(explode("-", $event[$m]["date"]));
                                    $list[$m + 1][0] = implode("/", $date);
                                    $list[$m + 1][1] = $event[$m]["announcement_title"];

                                    if ($lang=='el') {
                                        if ($event[$m]["linked_business_category"] == "bi_announcement_results") {
                                            $list[$m + 1][2] = "Αποτελέσματα";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_manage") {
                                            $list[$m + 1][2] = "Διοίκηση";
                                        } elseif ($event[$m]["linked_business_category"] == "bI_announcement_exist") {
                                            $list[$m + 1][2] = "Υπόσταση";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_changes") {
                                            $list[$m + 1][2] = "Εταιρικές Μεταβολές";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_ownership") {
                                            $list[$m + 1][2] = "Ιδιοκτησία";
                                        } else {
                                            $list[$m + 1][2] = "Λοιπά";
                                        }
                                    } else {
                                        if ($event[$m]["linked_business_category"] == "bi_announcement_results") {
                                            $list[$m + 1][2] = "Financial Results";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_manage") {
                                            $list[$m + 1][2] = "Management";
                                        } elseif ($event[$m]["linked_business_category"] == "bI_announcement_exist") {
                                            $list[$m + 1][2] = "Company Status";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_changes") {
                                            $list[$m + 1][2] = "Company Changes";
                                        } elseif ($event[$m]["linked_business_category"] == "bi_announcement_ownership") {
                                            $list[$m + 1][2] = "Ownership";
                                        } else {
                                            $list[$m + 1][2] = "Other";
                                        }
                                    }
                                } else {
                                    break;
                                }
                            }
                        } elseif ($key4 == "demographics") {

                            for ($r = 0; $r < count($event); $r++) {
                                $demographics_object = new stdClass();
                                $demographics_object->year = strval($event[$r]["year"]);
                                $demographics_object->demographics_score = $event[$r]["demographics_score"];
                                array_push($demographics, $demographics_object);
                            }
                        }
                    }

                    $event_table = array_filter($list);
                    break;

                case "businessNetwork": // business_tree_title, board_title, board_table, ownership_title, ownership_table, ownership_table_footnote

                    if ($lang=='el') {
                        $business_tree_title = "Διοίκηση & Ιδιοκτησία";
                        $management = array(array());
                        $management[0][0] = "Ονοματεπώνυμο";
                        $management[0][1] = "Θέση";
                        $management[0][2] = "Θητεία";
                        $management[0][3] = "Θέσεις";
                        $management[0][4] = "Θέσεις σε Τρίτες Εταιρείες (Ενεργές)";
                        $ownership = array(array());
                        $ownership[0][0] = "Ονοματεπώνυμο";
                        $ownership[0][1] = "%";
                        $ownership[0][2] = "Εισφορές";
                        $ownership_footnote = array();
                        $ownership_footnote[0] = "Σύνολο";
                    } else {
                        $business_tree_title = "Board & Ownership";
                        $management = array(array());
                        $management[0][0] = "Full Name";
                        $management[0][1] = "Role";
                        $management[0][2] = "Tenure period";
                        $management[0][3] = "Positions";
                        $management[0][4] = "Management in Other Companies (Active)";
                        $ownership = array(array());
                        $ownership[0][0] = "Full Name";
                        $ownership[0][1] = "%";
                        $ownership[0][2] = "Contributions";
                        $ownership_footnote = array();
                        $ownership_footnote[0] = "Total";
                    }

                    foreach ($value as $key5 => $network) {
                        
                        if ($key5 == "board_table") {
                            if (isset($network)) {
                                for ($p = 0; $p < count($network); $p++) {
                                    $board_member = array();
                                    $active_companies = 0;
                                    $board_member[0] = $network[$p]["full_name"];
                                    $board_member[1] = $network[$p]["board_role"];
                                    $board_member[2] = implode("/", array_reverse(explode("-", $network[$p]["fromDate"])))
                                        . " - " . implode("/", array_reverse(explode("-", $network[$p]["toDate"])));
                                    $board_member[3] = ($network[$p]["board_in_third"] != null) ? strval(count($network[$p]["board_in_third"])) : strval(0);

                                    if ($network[$p]["board_in_third"] != null) {

                                        foreach ($network[$p]["board_in_third"] as $company) {

                                            if ($company["isActive"]) {
                                                $active_companies += 1;
                                            }
                                        }
                                    }

                                    $board_member[4] = strval($active_companies);
                                    array_push($management, $board_member);
                                }
                            }
                        } elseif ($key5 == "ownership_table") {
                            $total_percentage = 0;
                            $total_amount = 0;
                            if (isset($network)) {
                                for ($q = 0; $q < count($network); $q++) {
                                    $ownership_member = array();

                                    if ($network[$q]["person"] == "HOLGER HOFMANN") {
                                        $ownership_member[0] = "ΜΗ ΔΙΑΘΕΣΙΜΗ ΠΛΗΡΟΦΟΡΙΑ";
                                    } else {
                                        $ownership_member[0] = $network[$q]["person"];
                                    }

                                    $ownership_member[1] = strval($network[$q]["partnerPercentage"] * 100) . "%";
                                    $total_percentage += $network[$q]["partnerPercentage"];
                                    $ownership_member[2] = number_format($network[$q]["amount"], 0, ",", ".");
                                    $total_amount += $network[$q]["amount"];
                                    $ownership_footnote[1] = numfmt_format($fmt6, $total_amount);
                                    $ownership_footnote[2] = strval($total_percentage * 100) . "%";
                                    array_push($ownership, $ownership_member);
                                }
                            }
                        } elseif ($key5 == "last_updated_board") {

                            if (isset($network)) {
                                foreach ($months as $m_key => $m_value) {

                                    if ($m_key == explode("/", $network)[1]) {
                                        $month = $m_value;
                                        $year = explode("/", $network)[2];
                                    }
                                }
                            }

                            if ($lang=='el') {
                                $management_title = "Διοίκηση";
                            } else {
                                $management_title = "Management Board";
                            }
                        } elseif ($key5 == "last_updated_ownership") {

                            if (isset($network)) {
                                foreach ($months as $month_key => $month_value) {

                                    if ($month_key == explode("/", $network)[1]) {
                                        $month2 = $month_value;
                                        $year2 = explode("/", $network)[2];
                                    }
                                }
                            }
                            if ($lang=='el') {
                                $ownership_title = "Ιδιοκτησία";
                            } else {
                                $ownership_title = "Ownership";
                            }
                        }
                        $xbus = null;
                        if (isset($data_short["data"]["businessNetwork"]["last_update"])) {
                            $xbus = $data_short["data"]["businessNetwork"]["last_update"];
                        }
                    }
                    break;





                case "market": // market_section_title, market_info_title, market_info_table, market_info_title2, market_info_table2,
                    $market_info_table = array(array(), array(), array(), array(), array(), array(), array(), array());
                    if ($lang=='el') {
                        $market_info_table[0][0] = "Πλήθος";
                        $market_info_table[0][1] = "Ποσοστό";
                        $market_info_table[1][0] = "Ενεργές Εταιρείες";
                        $market_info_table[2][0] = "Κύρια Δραστηριότητα";
                        $market_info_table[3][0] = "Αποκλειστική Δραστηριότητα";
                        $market_info_table[4][0] = "Δευτερεύουσα Δραστηριότητα";
                        $market_info_table[5][0] = "Με Έδρα στην Αττική";
                        $market_info_table[6][0] = "Με Έδρα στη Θεσσαλονίκη";
                        $market_info_table[7][0] = "Με Έδρα στην Περιφέρεια";
                        $market_info_table2 = array(array(), array(), array());
                        $market_info_table2[0][0] = "Ενεργές";
                        $market_info_table2[0][1] = "Ρυθμός Ιδρύσεων";
                        $market_info_table2[0][2] = "% Επιβίωσης 3yr";
                        $market_info_table2[0][3] = "% Επιβίωσης 5yr";
                        $market_info_table2[1][0] = "Ανώνυμες Εταιρείες";
                        $market_info_table2[2][0] = "Λοιπές Εταιρείες (πλην ΑΕ)";
                        $adverse_events_table = array(array(), array(), array(), array());
                        $adverse_events_table[0][0] = "Ποσό";
                        $adverse_events_table[0][1] = "Εταιρείες";
                        $adverse_events_table[1][0] = "Οφειλές Δημοσίου";
                        $adverse_events_table[2][0] = "Πλειστηριασμοί";
                        $adverse_events_table[3][0] = "Πρόστιμα";
                        $public_money_market_table = array(array(), array(), array(), array());
                        $public_money_market_table[0][0] = "Ποσό";
                        $public_money_market_table[0][1] = "Εταιρείες";
                        $public_money_market_table[1][0] = "Εργα ΕΣΠΑ 2014-21";
                        $public_money_market_table[2][0] = "Πληρωμές Δημοσίου (Συνολικά)";
                        $public_money_market_table[3][0] = "Πληρωμές Δημοσίου";
                    } else {
                        $market_info_table[0][0] = "Count";
                        $market_info_table[0][1] = "Percentage";
                        $market_info_table[1][0] = "Active Companies";
                        $market_info_table[2][0] = "Main CPA";
                        $market_info_table[3][0] = "Exclusive Activity";
                        $market_info_table[4][0] = "Secondary Activity";
                        $market_info_table[5][0] = "Based in Attica";
                        $market_info_table[6][0] = "Based in Thessaloniki";
                        $market_info_table[7][0] = "Based in rest greek region";
                        $market_info_table2 = array(array(), array(), array());
                        $market_info_table2[0][0] = "Active";
                        $market_info_table2[0][1] = "Establishment Rate";
                        $market_info_table2[0][2] = "% 3yr Survival";
                        $market_info_table2[0][3] = "% 5yr Survival";
                        $market_info_table2[1][0] = "Public Limited Companies";
                        $market_info_table2[2][0] = "Other Companies (excluding SA)";
                        $adverse_events_table = array(array(), array(), array(), array());
                        $adverse_events_table[0][0] = "Amount";
                        $adverse_events_table[0][1] = "Companies";
                        $adverse_events_table[1][0] = "Debts to the State";
                        $adverse_events_table[2][0] = "Auctions";
                        $adverse_events_table[3][0] = "Fines";
                        $public_money_market_table = array(array(), array(), array(), array());
                        $public_money_market_table[0][0] = "Amount";
                        $public_money_market_table[0][1] = "Companies";
                        $public_money_market_table[1][0] = "NSRF 2014-2021";
                        $public_money_market_table[2][0] = "State payments (Total)";
                        $public_money_market_table[3][0] = "State Payments";                        
                    }

                    foreach ($value as $key6 => $market) {

                        if ($key6 == "total") {

                            foreach ($market as $key7 => $market_object) {

                                if ($key7 == "market_info_table") {

                                    foreach ($market_object as $key8 => $object) {

                                        if ($key8 == "active_companies") {
                                            $market_info_table[1][1] = number_format($object["count"], 0, ",", ".");
                                        } elseif ($key8 == "main_activity") {
                                            $market_info_table[2][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[2][2] = numfmt_format($fmt2, $object["percent"]);
                                        } elseif ($key8 == "exclusive_activity") {
                                            $market_info_table[3][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[3][2] = numfmt_format($fmt2, $object["percent"]);
                                        } elseif ($key8 == "secondary_activity") {
                                            $market_info_table[4][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[4][2] = numfmt_format($fmt2, $object["percent"]);
                                        } elseif ($key8 == "athens_active") {
                                            $market_info_table[5][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[5][2] = numfmt_format($fmt2, $object["percent"]);
                                        } elseif ($key8 == "thessaloniki_active") {
                                            $market_info_table[6][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[6][2] = numfmt_format($fmt2, $object["percent"]);
                                        } elseif ($key8 == "restGreece_active") {
                                            $market_info_table[7][1] = number_format($object["count"], 0, ",", ".");
                                            $market_info_table[7][2] = numfmt_format($fmt2, $object["percent"]);
                                        }
                                    }
                                } elseif ($key7 == "market_info_table_birth_rate") {

                                    foreach ($market_object as $key9 => $object2) {

                                        if ($key9 == "plc") {
                                            $market_info_table2[1][1] = numfmt_format($fmt, $object2["total_actives"]);
                                            $market_info_table2[1][2] = numfmt_format($fmt2, $object2["birth_rate"]);
                                            $market_info_table2[1][3] = numfmt_format($fmt2, $object2["survival_3y"]);
                                            $market_info_table2[1][4] = numfmt_format($fmt2, $object2["survival_5y"]);
                                        } elseif ($key9 == "rest") {
                                            $market_info_table2[2][1] = numfmt_format($fmt, $object2["total_actives"]);
                                            $market_info_table2[2][2] = numfmt_format($fmt2, $object2["birth_rate"]);
                                            $market_info_table2[2][3] = numfmt_format($fmt2, $object2["survival_3y"]);
                                            $market_info_table2[2][4] = numfmt_format($fmt2, $object2["survival_5y"]);
                                        }
                                    }
                                } elseif ($key7 == "adverse_events_table") {

                                    foreach ($market_object as $key10 => $object3) {

                                        if ($key10 == "public_debts") {
                                            $adverse_events_table[1][1] = numfmt_format($fmt6, $object3["amount"]);
                                            $adverse_events_table[1][2] = strval($object3["count_companies"]);
                                        } elseif ($key10 == "fines") {
                                            $adverse_events_table[3][1] = numfmt_format($fmt6, $object3["amount"]);
                                            $adverse_events_table[3][2] = strval($object3["count_companies"]);
                                        } elseif ($key10 == "auctions") {
                                            $adverse_events_table[2][1] = numfmt_format($fmt6, $object3["amount"]);
                                            $adverse_events_table[2][2] = strval($object3["count_companies"]);
                                        }
                                    }
                                } elseif ($key7 == "performance_table") {
                                    $performance_table2 = array(array());
                                    if ($lang=='el') {
                                        $performance_table2[0][0] = "";
                                        $performance_table2[0][1] = "Πωλήσεις";
                                        $performance_table2[0][2] = "Αριθμός Εταιρειών";
                                        $performance_table2[0][3] = "Ανοδική τάση";
                                        $performance_table2[0][4] = "Καθοδική Τάση";
                                        $performance_table2[0][5] = "Σταθερή Τάση";
                                    } else {
                                        $performance_table2[0][0] = "";
                                        $performance_table2[0][1] = "Turnover";
                                        $performance_table2[0][2] = "Company Count";
                                        $performance_table2[0][3] = "Upward Trend";
                                        $performance_table2[0][4] = "Downward Trend";
                                        $performance_table2[0][5] = "Stable Trend";
                                    }
                                    $performance_table_years = array();

                                    if ($market_object != null)
                                        for ($s = 0; $s < count($market_object); $s++) {

                                            if ($market_object[$s]["name"] == "revenue") {

                                                for ($t = 0; $t < count($market_object[$s]["data"]); $t++) {
                                                    // $performance_table2[$t + 1][0] = strval($market_object[$s]["data"][$t]["year"]);  
                                                    if (! isset($performance_table2[$t + 1]))
                                                        $performance_table2[$t + 1] = array();
                                                    array_push($performance_table2[$t + 1], strval($market_object[$s]["data"][$t]["year"]));
                                                    // $performance_table2[$t + 1][1] = numfmt_format($fmt6, $market_object[$s]["data"][$t]["value"]);
                                                    array_push($performance_table2[$t + 1], numfmt_format($fmt6, $market_object[$s]["data"][$t]["value"]));
                                                    array_push($performance_table_years, $market_object[$s]["data"][$t]["year"]);
                                                }
                                            } elseif ($market_object[$s]["name"] == "market_total_companies") {

                                                for ($u = 0; $u < count($market_object[$s]["data"]); $u++) {
                                                    // $performance_table2[$u + 1][2] = strval($market_object[$s]["data"][$u]["value"]);
                                                    array_push($performance_table2[$u + 1], strval($market_object[$s]["data"][$u]["value"]));
                                                }
                                            } elseif ($market_object[$s]["name"] == "market_trends_upwards") {

                                                for ($g = 0; $g < count($market_object[$s]["data"]); $g++) {
                                                    // $performance_table2[$g + 1][3] = strval($market_object[$s]["data"][$g]["value"]) . "%";
                                                    array_push($performance_table2[$g + 1], strval($market_object[$s]["data"][$g]["value"]) . "%");
                                                }
                                            } elseif ($market_object[$s]["name"] == "market_trends_downwards") {

                                                for ($z = 0; $z < count($market_object[$s]["data"]); $z++) {
                                                    // $performance_table2[$z + 1][4] = strval($market_object[$s]["data"][$z]["value"]) . "%";
                                                    array_push($performance_table2[$z + 1], strval($market_object[$s]["data"][$z]["value"]) . "%");
                                                }
                                            } elseif ($market_object[$s]["name"] == "market_trends_stable") {

                                                for ($d = 0; $d < count($market_object[$s]["data"]); $d++) {
                                                    // $performance_table2[$d + 1][5] = strval($market_object[$s]["data"][$d]["value"]) . "%";
                                                    array_push($performance_table2[$d + 1], strval($market_object[$s]["data"][$d]["value"]) . "%");
                                                }
                                            }
                                        }

                                    if (count($performance_table_years) > 0)
                                        $max_perfromance_table_years = strval(max($performance_table_years));
                                    if (count($performance_table_years) > 0)
                                        $min_perfromance_table_years = strval(min($performance_table_years));

                                    if (count($performance_table_years) > 0) {
                                        if ($lang=='el') {
                                            $performance_title2 = "Επιδόσεις (" . $min_perfromance_table_years . "-" . $max_perfromance_table_years . ")";
                                        } else {
                                            $performance_title2 = "Performance (" . $min_perfromance_table_years . "-" . $max_perfromance_table_years . ")";
                                        }
                                    } else {
                                        if ($lang=='el') {
                                            $performance_title2 = "Επιδόσεις";
                                        } else {
                                            $performance_title2 = "Performance";
                                        }
                                    }
                                } elseif ($key7 == "market_public_money") {

                                    foreach ($market_object as $key12 => $object4) {

                                        if ($key12 == "espa_market_table") {
                                            $public_money_market_table[1][1] = numfmt_format($fmt, $object4["amount"]);
                                            $public_money_market_table[1][2] = strval($object4["count_companies"]);
                                        } elseif ($key12 == "public_payments") {
                                            $public_money_market_table[2][1] = numfmt_format($fmt, $object4["amount"]);
                                            $public_money_market_table[2][2] = strval($object4["count_companies"]);
                                        } elseif ($key12 == "public_payments_year") {
                                            $public_money_market_table[3][1] = numfmt_format($fmt, $object4["amount"]);
                                            $public_money_market_table[3][2] = strval($object4["count_companies"]);
                                        }
                                    }
                                } elseif ($key7 == "last_updated_market_birth_rate") {
                                    if ($lang=='el') {
                                        $market_info_title2 = "Ρυθμός Ιδρύσεων & Επιβίωσης (" . explode("/", $market_object)[1] . ")";
                                    } else {
                                        $market_info_title2 = "Establishment & Survival Rate (" . explode("/", $market_object)[1] . ")";
                                    }
                                } elseif ($key7 == "last_updated_market_info") {
                                    $demographics_year = explode("/", $market_object)[1];
                                    if ($lang=='el') {
                                        $market_info_title = "Δημογραφία (" . $demographics_year . ")";
                                    } else {
                                        $market_info_title = "Demography (" . $demographics_year . ")";
                                    }
                                } elseif ($key7 == "last_updated_market_public_money") {

                                    foreach ($months as $key13 => $month3) {

                                        if ($key13 == explode("/", $market_object)[0]) {
                                            if ($lang=='el') {
                                                $public_money_market_title = "Δημόσιο Χρήμα (" . $month3 . " " . explode("/", $market_object)[1] . ")";
                                            } else {
                                                $public_money_market_title = "Public Money (" . $month3 . " " . explode("/", $market_object)[1] . ")";
                                            }
                                        }
                                    }
                                } elseif ($key7 == "last_updated_market_adverse_events") {

                                    foreach ($months as $key14 => $month4) {

                                        if ($key14 == explode("/", $market_object)[0]) {
                                            if ($lang=='el') {
                                                $adverse_events_title = "Δυσμενή Γεγονότα (" . $month4 . " " . explode("/", $market_object)[1] . ")";
                                            } else {
                                                $adverse_events_title = "Adverse Events (" . $month4 . " " . explode("/", $market_object)[1] . ")";
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if (isset($data["data"]["market"]["total"]["market_concentration"])) {
                        $mconc = $this->sort_rating($data["data"]["market"]["total"]["market_concentration"]);
                    } else {
                        $mconc = null;
                    }

                    if (isset($data_short["data"]["market"]["last_update"])) {
                        $xmar = $data_short["data"]["market"]["last_update"];
                    }
                    break;

                case "credit":

                    if (isset($data["data"]["credit"]["credit_rating"])) {
                        $credit_score = $this->sort_rating($data["data"]["credit"]["credit_rating"]);
                    } else {
                        $credit_score = null;
                    }

                    if (isset($data["data"]["credit"]["market_segmentation"])) {
                        $mseg = $this->sort_rating($data["data"]["credit"]["market_segmentation"]);
                        if (count($mseg) > 0) {
                            $yearmat = 0;
                            foreach ($mseg as $k => $v) {
                                if (isset($v["year"])) {
                                    if ($v["year"] > $yearmat) {
                                        $mseg_last_year = $v;
                                        $yearmat = $v["year"];
                                    }
                                }
                            }
                            unset($yearmat);
                        } else {
                            $mseg_last_year = null;
                        }
                    } else {
                        $mseg = null;
                        $mseg_last_year = null;
                    }



                    if (isset($data["data"]["credit"]["trend"])) {
                        $mtrends = $this->sort_rating($data["data"]["credit"]["trend"]);
                    } else {
                        $mtrends = null;
                    }

                    if (isset($data_short["data"]["credit"]["last_update"])) {
                        $xcre = $data_short["data"]["credit"]["last_update"];
                    } else {
                        $xcre = null;
                    }

                    break;
                
                default:
                    break;

            }
        }
        if ($lang=='el') {
            $company_birth_date_title = "Ημερομηνία Ιδρυσης";
            $summary_title = "Η Εταιρεία με μια Ματιά";
            $events_title ="Εταιρικά Γεγονότα";
            $credit_score_page = "Αξιολόγηση Εταιρείας";
            $opinions_title = "Αξιολόγηση του Ορκωτού Ελέγχου";
            $unfavorable_page_title = "Δυσμενή Γεγονότα";
            $market_section_title = "Παλμός της Αγοράς";
            $basic_company_info_table = "Βασικά στοιχεία";
            $important_events = "Σημαντικά Γεγονότα";
            $summary_adverse_title = "Δυσμενή";
            $lang_img = "gr";
            $summary_evaluation_title = "Επισκόπηση Αξιολόγησης Εταιρείας";
            $demographics_title = "Δείκτης Πληρότητας Οικονομικών & Εταιρικών Στοιχείων";
            $announcement_count_title = "Ανακοινώσεις ανά κατηγορία";
            $company_events_list_title = "Πρόσφατες Ανακοινώσεις";
            $public_money_summary = "Με μια Ματιά";
            $pm_plot_title = "Ποσό & Τύπος Απόφασης ανά Έτος";
            $pm_pie_plot_title = "Μεγαλύτεροι Πληρωτές";
            $last_update_page = "Τελευταία&nbsp;Ενημέρωση&nbsp;"; 
            $credit_score_title = "Πιστοληπτική Διαβάθμιση";
            $market_segmentation_title = "Κατάταξη στην Αγορά";
            $market_trends_title = "Τάση";
            $behavior_assessment_index_title = "Δείκτης Αξιολόγησης";
            $exchange_behavior_title = "Συναλλακτική Συμπεριφορά";
            $market_concentration_title = "Βαθμός Συγκέντρωσης";
            $table_asterisk_pulse = "3yr = 3 ετών και αντιστοίχως 5yr = 5 ετών";
            $table_asterisk_ownership = "Στον πίνακα ιδιοκτησίας εμφανίζονται εταιρείες που έχουν συσταθεί μέσω της Υπηρεσίας Μιας Στάσης. Οι πληροφορίες του πίνακα ιδιοκτησίας έχουν αναμορφωθεί είτε ως προς τη σύνθεση, είτε ως προς τις εισφορές, είτε ως προς το ποσοστό συμμετοχής, με βάση την πιο πρόσφατη τροποποίηση καταστατικού (ή συναφείς ανακοινώσεις) και την πληροφορία που περιέχεται σε αυτή κάθε φορά.";
            $foot = [
                //0
                "Μεθοδολογία & Ευρετήριο Όρων",
                //1
                "Το Business Report της Linked Business είναι η πρώτη εταιρική αναφορά που παράγεται από ανοιχτά δεδομένα τα οποία συλλέγει από Δημόσια μητρώα και κατόπιν επεξεργάζεται και επαυξάνει. Ενδεικτικά, μερικές από τις πηγές που χρησιμοποιούνται από τη Linked Business για τη δημιουργία της αναφοράς είναι: ΓΕΜΗ, Τοπικά Επιμελητήρια, Πρόγραμμα Διαύγεια, ΕΣΠΑ, Υπουργείο Ανάπτυξης, Eurostat, Περιφέρειες, ΑΑΔΕ, Πλειστηριασμοί, εταιρικές ιστοσελίδες και δημόσια διαθέσιμα δεδομένα από μηχανές αναζήτησης.",
                //2
                "Εταιρικά Γεγονότα",
                //3
                "Η Linked Business, με την χρήση τεχνολογιών ΑΙ, επεξεργάζεται και κατηγοριοποιεί αυτόματα τα εταιρικά γεγονότα με βάση το περιεχόμενο τους σε θεματικές κατηγορίες (π.χ. Αποτελέσματα, Νομικές Ενέργειες) έτσι ώστε να είναι χρήσιμα στην λήψη επιχειρηματικών αποφάσεων.",
                //4
                "Το εταιρικό γεγονός ορίζεται ως ένα οποιοδήποτε γεγονός που μεταβάλλει τα βασικά συστατικά μιας εταιρικής δομής και καταγράφεται στο Γενικό Εμπορικό Μητρώο (ΓΕΜΗ).",
                //5
                "Τα εταιρικά γεγονότα δεν θα πρέπει να συγχέονται με τα εταιρικά <b>νέα</b>, που περιλαμβάνουν οποιοδήποτε πληροφορία που σχετίζεται με την εταιρία (π.χ. εκδήλωση, βράβευση).",
                //6
                "Δημόσιο Χρήμα",
                //7
                "Εγκρίσεις",
                //8
                "Πριν από οποιαδήποτε ενέργεια για την εκτέλεση ενός δημόσιου έργου, απαιτείται η έγκριση από τον αρμόδιο φορέα του ελληνικού κράτους. Οι αποφάσεις έγκρισης δημοσιεύονται στην πλατφόρμα ΔΙΑΥΓΕΙΑ και περιέχουν λεπτομερείς πληροφορίες για τα αντίστοιχα έργα και τις δημόσιες δαπάνες.",
                //9
                "Πληρωμές",
                //10
                "Εκτός από τις εγκρίσεις δημόσιων δαπανών, δημοσιοποιούνται επίσης τα επίσημα έγγραφα των αντίστοιχων πληρωμών αυτών των έργων. Η σχετική απόφαση δημοσιεύεται στην πλατφόρμα της ΔΙΑΥΓΕΙΑ και περιλαμβάνει παρόμοιες πληροφορίες με αυτές που περιλαμβάνονται στα έγγραφα έγκρισης που αναφέρονται παραπάνω.",
                //11
                "Αναθέσεις",
                //12
                "Επιπλέον, τα έγγραφα ανάθεσης έργου με τη σειρά τους αποτελούν αναπόσπαστο μέρος της δημόσιας διαδικασίας και περιέχουν πληροφορίες σχετικά με τους δημόσιους φορείς και τους αναδόχους του έργου. Όπως και στις δύο προηγούμενες περιπτώσεις, έτσι και στα επίσημα έγγραφα που δημοσιεύονται στην πλατφόρμα της ΔΙΑΥΓΕΙΑ σχετικά με τις αναθέσεις έργων, τα δημοσιευμένα δεδομένα είναι ακριβώς τα ίδια με αυτά των εγκρίσεων και των πληρωμών.",
                //13
                "Κατακυρώσεις",
                //14
                "Ένα ακόμα στάδιο της διαδικασίας των προκηρύξεων δημοσίων έργων του ελληνικού κράτους είναι η κατακύρωση του έργου έτσι ώστε να μπορεί να προχωρήσει η εκτέλεση του. Τα επίσημα έγγραφα που δημοσιεύονται στην πλατφόρμα της ΔΙΑΥΓΕΙΑ έχουν ακριβώς τις ίδιες πληροφορίες με τις αναθέσεις.",
                //15
                "ΕΣΠΑ",
                //16
                "Το Εταιρικό Σύμφωνο για το Πλαίσιο Ανάπτυξης (ΕΣΠΑ) είναι ένα βασικό στρατηγικό σχέδιο για την ανάπτυξη της χώρας με τη βοήθεια σημαντικών πόρων που προέρχονται από τα Ευρωπαϊκά Διαρθρωτικά και Επενδυτικά Ταμεία της Ευρωπαϊκής Ένωσης. Πληροφορίες σχετικά με τα προγράμματα ΕΣΠΑ αναρτώνται στην ιστοσελίδα του Υπουργείου Ανάπτυξης και Επενδύσεων της Ελληνικής Κυβέρνησης.",
                //17
                "Αξιολόγηση Εταιρείας",
                //18
                "Κατάταξη στην Αγορά",
                //19
                "Η κατάταξη της εταιρείας στην αγορά υπολογίζεται με βάση τις πωλήσεις της εταιρείας σε σχέση με τις πωλήσεις της αγοράς στο σύνολό της.",
                //20
                "Τάση",
                //21
                "Ο δείκτης αυτός αφορά στην οικονομική τάση της υπό εξέταση εταιρείας στη βάση των πωλήσεών της. Για τον υπολογισμό του δείκτη είναι απαραίτητη η ύπαρξη αριθμού για τις πωλήσεις ανά έτος για δύο (2) διαδοχικά έτη. Επίσης, για την ικανοποίηση της ιδίας συνθήκης, το πιο πρόσφατο έτος δεν μπορεί να απέχει από το τρέχον περισσότερο από δύο (2) έτη.",
                //22
                "Οι νεοιδρυθείσες εταιρίες οι οποίες δεν παρουσιάζουν πωλήσεις και άρα δεν είναι δυνατός ο υπολογισμός του δείκτη, επισημαίνονται ως <b>«σε ανάπτυξη»</b>.",
                //23
                "Οι εταιρίες για τις οποίες δεν ικανοποιούνται τα κριτήρια υπολογισμού του δείκτη που αναφέρθηκαν παραπάνω επισημαίνονται ως <b>«δεν αξιολογείται»</b>.",
                //24
                "Αξιολόγηση του Ορκωτού Ελέγχου",
                //25
                "Η Linked Business, με την χρήση τεχνολογιών ΑΙ, επεξεργάζεται και κατηγοριοποιεί αυτόματα τις ανακοινώσεις του Ορκωτού Ελέγχου σε μια κλίμακα αξιολόγησης με δέκα (10) βαθμίδες.",
                //26
                "Παλμός της Αγοράς",
                //27
                "Ρυθμός Ιδρύσεων",
                //28
                "Ο ρυθμός ιδρύσεων ανά έτος υπολογίζεται από τον αριθμό ιδρύσεων κατά το έτος αναφοράς, προς το σύνολο των ενεργών εταιρειών μέχρι και του έτους αυτού. Το έτος αναφοράς ορίζεται ως το πιο πρόσφατα ολοκληρωμένο οικονομικό έτος.",
                //29
                "% Επιβίωσης 3 ή 5 Ετών",
                //30
                "Σύμφωνα με τη Eurostat* το ποσοστό επιβίωσης των νεοιδρυθέντων εταιρειών για μια συγκεκριμένη περίοδο αναφοράς ορίζεται ως ο αριθμός των εταιρειών που ιδρύθηκαν στο έτος που ορίζει την αρχή της περιόδου αναφοράς και επιβίωσαν μέχρι του έτους που ορίζει το πέρας της περιόδου αναφοράς, προς το συνολικό αριθμό εταιρειών που ιδρύθηκαν στο έτος που ορίζει την αρχή της περιόδου αναφοράς.",
                //31
                "Βαθμός Συγκέντρωσης της Αγοράς",
                //32
                "Ο βαθμός συγκέντρωσης της αγοράς υπολογίζεται με βάση τον δείκτη Herfindahl–Hirschman (HHI). Ο δείκτης χρησιμοποιείται για τη μέτρηση του επιπέδου συγκέντρωσης της αγοράς σε έναν δεδομένο κλάδο. Είναι κοινά αποδεκτός δείκτης του ανταγωνισμού της αγοράς και ευρέως γνωστός. Η Linked Business χρησιμοποιεί την ταξινόμηση των επιπέδων συγκέντρωσης που χρησιμοποιεί η Ευρωπαϊκή Επιτροπή βάσει των κανονισμών του Συμβουλίου για τον έλεγχο των συγκεντρώσεων μεταξύ επιχειρήσεων (Επίσημη Εφημερίδα της Ευρωπαϊκής Ένωσης C 31/03).",
                //33
                "Δεδομένα κατά Δημόσια Δήλωσης της Εταιρείας",
                //34
                "Όλα τα πρωτογενή δεδομένα που διαχειρίζεται η Linked Business προέρχονται από δημόσιες ανοιχτές πηγές κι επομένως, αποτελούν είτε δημόσια δήλωση της εταιρείας (ανακοινώσεις που αναρτώνται στο ΓΕΜΗ) είτε αποτελούν αποφάσεις πράξεων των κυβερνητικών και διοικητικών οργάνων που διατίθενται σε ιστοτόπους όπως είναι η Διαύγεια. Η Linked Business δεν φέρει καμία ευθύνη για δεδομένα που έχουν δηλωθεί, ανακοινωθεί ή αναρτηθεί λανθασμένα ή περιέχουν ελλείψεις.",
                //35
                "Δημοσιευμένα Οικονομικά Αποτελέσματα",
                //36
                "Όλες οι μεταβλητές που απαιτούν χρήση οικονομικών αποτελεσμάτων για τον υπολογισμό τους στο έτος αναφοράς (τρέχον οικονομικό έτος - 1), λαμβάνουν ως πλήθος εταιρειών όσες έχουν δημοσιεύσει οικονομικά αποτελέσματα στο ΓΕΜΗ για το τρέχον οικονομικό έτος - 1, μέχρι και το χρόνο παραγωγής του report. Η συγκεκριμένη υποχρέωση δημοσίευσης οικονομικών αποτελεσμάτων αφορά κυρίως τις ανώνυμες εταιρείες, τις εταιρείες περιορισμένης ευθύνης, τις ιδιωτικές κεφαλαιουχικές εταιρείες και τις ετερόρρυθμες κατά μετοχές εταιρείες. Η λήξη της περιόδου δημοσίευσης των οικονομικών αποτελεσμάτων στο ΓΕΜΗ, οποιουδήποτε οικονομικού έτους, ορίζεται στις 30 Σεπτεμβρίου του επόμενου έτους, με εξαίρεση το έτος 2022, όπου η καταληκτική ημερομηνία δημοσίευσης οικονομικών αποτελεσμάτων στο ΓΕΜΗ ορίστηκε στις 19 Νοεμβρίου.",
                //37
                "Γνωστοποίηση",
                //38
                "Η παρούσα αναφορά περιέχει δεδομένα, τα οποία προέρχονται από ανοιχτές δημόσιες πηγές και παρέχονται 'ως έχουν' υπό συγκεκριμένη άδεια χρήσης, χωρίς καμία περαιτέρω επεξεργασία από την πλευρά της Linked Business Μονοπρόσωπης ΑΕ. Επ'ουδενί δε συνιστά η παρούσα αναφορά επιχειρηματική συμβουλή και η Linked Business δεν έχει καμία ευθύνη σχετικά με τον τρόπο αξιοποίησής απο τον χρήστη.",
                //39
                "Είστε διαχειριστής πιστώσεων ή διαχειριστής περιουσιακών στοιχείων; Προσπαθείτε να επεκτείνετε τη βάση των πελατών σας; Ή προσπαθείτε να οργανώσετε τα δεδομένα του CRM σας και να εξάγετε γνώση από αυτά;",
                //40
                "Η Linked Business παρέχει ολοκληρωμένες λύσεις για τις κρίσιμες επιχειρηματικές σας ανάγκες",
                //41
                "Κατανοήστε το Παρελθόν & Προβλέψτε την Μελλοντική Απόδοση",
                //42
                "Η Linked Business παρέχει υπηρεσίες know-your-customer (KYC) σε δημόσιους ή ιδιωτικούς φορείς και οργανισμούς με στόχο τον <b>υπολογισμό και τη μείωση του κινδύνου.</b> Αυτό είναι ιδιαίτερα σημαντικό για τους οργανισμούς που διαχειρίζονται και επενδύουν κεφάλαια για την υποστήριξη δημόσιων ή ιδιωτικών έργων. Σε αυτή την περίπτωση, οι υπηρεσίες της Linked Business είναι εξαιρετικά σημαντικές για την παροχή είτε καθυστερημένων δεικτών για εργολάβους που επιδιώκουν να αναλάβουν δημόσια ή ιδιωτικά έργα, είτε προγνωστικών δεικτών.",
                //43
                "Μάθετε περισσότερα στο: ",
                //44
                "Βιώσιμη Ανάπτυξη με την Πλουσιότερη Παραγωγή B2B Leads",
                //45
                "Τα sales leads μπορούν να είναι πολύ περισσότερα από απλές επαφές σε ένα υπολογιστικό φύλλο. Η Linked Business εμπλουτίζει τα sales leads πολύ πέρα από τα στοιχεία επικοινωνίας και <b>παρέχει sales qualified leads (SQL) χρησιμοποιώντας τα πιο ακριβή μοντέλα τμηματοποίησης της αγοράς.</b> Οι πληροφορίες περιλαμβάνουν πλήρη εμπορική δραστηριότητα, στοιχεία επικοινωνίας, διεύθυνση και τοποθεσία στον χάρτη, εύρος οικονομικών αποτελεσμάτων, διαχειριστές και μετόχους εταιρειών, δείκτες οικονομικής υγείας, και κρυφές σχέσεις μεταξύ εταιρειών και ατόμων στο επιχειρηματικό δίκτυο. Αυτό το έξυπνο σύνολο δεδομένων επιτρέπει στις B2B εταιρείες να στοχεύουν με ακρίβεια τους ιδανικούς υποψήφιους, να ανακαλύπτουν ευκαιρίες όπως νεοσύστατες εταιρείες, ευκαιρίες upsell και cross-sell, και να αποκτούν βαθιά γνώση και πληροφορίες για την αγορά.",
                //46
                "Άμεση & Χωρίς Σφάλματα Αυτοματοποίηση Onboarding",
                //47
                "Το LB Digital Onboarding είναι μια οριζόντια υπηρεσία για τη Σουίτα Εργαλείων της Linked Business, που απλοποιεί και επιταχύνει την παραδοσιακή διαδικασία onboarding για νέους πελάτες, διατηρώντας χαμηλά τα κόστη της διαδικασίας και υψηλή την ποιότητα των δεδομένων του CRM.",
                //48
                "Τα κύρια πλεονεκτήματα της χρήσης του ψηφιακού onboarding είναι:",
                //49
                "Απλή ή Ανύπαρκτη Διαχείριση",
                //50
                "Μειωμένα Κόστη",
                //51
                "Συνέπεια",
                //52
                "Ποιότητα Δεδομένων",
                //53
                "Συμμόρφωση",
                //54
                "Πολυδιάστατη Γνώση",
                //55
                "Εξασφαλίστε ακριβή, πλήρη, συνεπή, ασφαλή και ενημερωμένα δεδομένα!",
                //56
                "Είναι μια ευρέως γνωστή αλήθεια ότι τα δεδομένα που δεν διαχειριζόμαστε κοστίζουν 1 μονάδα κατά την πρόληψη και διαχείριση, 10 μονάδες κατά τη διόρθωση και 100 μονάδες να αντιμετωπιστούν οι συνέπειες. Το LB Master Data Governance είναι μια ολοκληρωμένη υπηρεσία που επεκτείνεται από εφαρμογές καθαρισμού και ενίσχυσης δεδομένων μέχρι τον ορισμό και τη μοντελοποίηση πολιτικών, κανόνων και διαδικασιών που εξασφαλίζουν υψηλή ποιότητα δεδομένων σε όλο τον κύκλο ζωής των επιχειρηματικών δεδομένων.",
                //57
                "Η υπηρεσία παρέχεται ως ένα εξατομικευμένο έργο που ασχολείται με κακά επιχειρηματικά δεδομένα μέσα στα συστήματα μιας επιχείρησης. Τυπικές περιπτώσεις τέτοιων δεδομένων περιλαμβάνουν:",
                //58
                "Μη επαληθευμένα αρχεία",
                //59
                "Διπλά αρχεία",
                //60
                "Κατεστραμμένα αρχεία",
                //61
                "Παρωχημένα αρχεία",
                //62
                "Ληγμένα αρχεία"                
            ];
            $trans_content = [
                //0
                "Η θητεία εμφανίζεται όπως έχει αποτυπωθεί στην πηγή. Δεν έχει δημοσιευθεί νεότερη πληροφορία μέχρι στιγμής.",
                //1
                "Το πεδίο αφορά στη συμμετοχή των μελών διοίκησης σε τρίτες εταιρείες. Ο αριθμός εντός παράνθεσης αναφέρεται σε ενεργές εταιρείες και ο αριθμός εκτός παράνθεσης στο σύνολο των εταιρειών.",
                //2
                "Δεν υπάρχει τιμή για την έναρξης της θητείας στην πηγή.",
                //3
                "Δεν υπάρχει τιμή για τη λήξη της θητείας στην πηγή.",
                //4
                "Σε αυτή την έκδοση εμφανίζονται πληροφορίες για το Ιδιοκτησιακό καθεστώς μόνο για τις εταιρείες που έχουν συσταθεί μέσω Υπηρεσίας Μιας Στάσης.",
                //5
                "Στον πίνακα ιδιοκτησίας εμφανίζονται εταιρείες που έχουν συσταθεί μέσω της Υπηρεσίας Μιας Στάσης. Οι πληροφορίες του πίνακα ιδιοκτησίας έχουν αναμορφωθεί είτε ως προς τη σύνθεση, είτε ως προς τις εισφορές, είτε ως προς το ποσοστό συμμετοχής, με βάση την πιο πρόσφατη τροποποίηση καταστατικού (ή συναφείς ανακοινώσεις) και την πληροφορία που περιέχεται σε αυτή κάθε φορά.",
                //6
                "Η εταιρεία δύναται να βαρύνεται με δυσμενή γεγονότα πλην οφειλών, προστίμων και πλειστηριασμών, τα οποία προσμετρώνται στον υπολογισμό του δείκτη συναλλακτικής συμπεριφορά.",
                //7
                "Οι δημοσιευμένες οικονομικές καταστάσεις συντάσσονται κατά ΕΛΠ."
            ];
        } else {
            $company_birth_date_title = "Registration Date";
            $summary_title = "Company Overview";
            $events_title = "Corporate Announcements";
            $credit_score_page = "Company Evaluation";
            $opinions_title = "Statutory Audit Evaluation";
            $unfavorable_page_title = "Adverse Corporate Events";
            $market_section_title = "Market Pulse";
            $basic_company_info_table = "Basic Information";
            $important_events = "Important Events";
            $summary_adverse_title = "Adverse";
            $lang_img = "en";
            $summary_evaluation_title = "Company Evaluation Overview";
            $demographics_title = "Financial & Corporate Data Completeness Index";
            $announcement_count_title = "Announcements by category";
            $company_events_list_title = "Recent Announcements";
            $public_money_summary = "At a Glance";
            $pm_plot_title = "Amount & Type of Decision per Year";
            $pm_pie_plot_title = "Larger Payers";
            $last_update_page = "Last&nbsp;Update&nbsp;"; 
            $credit_score_title = "Credit rating";
            $market_segmentation_title = "Market Ranking";
            $market_trends_title = "Trend";
            $behavior_assessment_index_title = "Evaluation Index";
            $exchange_behavior_title = "Transaction Behaviour";
            $market_concentration_title = "Concentration Rate";
            $table_asterisk_pulse = "3yr = 3 years and respectively 5yr = 5 years";
            $table_asterisk_ownership = "The ownership table displays companies that have been established through the One-Stop Service. The information in the ownership table has been revised either in terms of composition, contributions, or participation percentage, based on the most recent amendment of the articles of association (or related announcements) and the information contained therein each time.";
            $foot = [
                //0
                "Methodology & Index of Terms",
                //1
                "The Business Report by Linked Business is the first corporate report produced from open data, which is collected from public registries and subsequently processed and enhanced. Indicatively, some of the sources used by Linked Business for the creation of the report include: the General Commercial Registry (GEMI), Local Chambers of Commerce, the Diavgeia Program, the NSRF, the Ministry of Development, Eurostat, Regional Authorities, the Independent Authority for Public Revenue (AADE), Auctions, corporate websites, and publicly available data from search engine.",
                //2
                "Corporate Events",
                //3
                "Linked Business, using AI technologies, automatically processes and categorizes corporate events based on their content into thematic categories (e.g., Results, Legal Actions) so that they are useful for business decision-making.",
                //4
                "A corporate event is defined as any event that alters the basic components of a corporate structure and is recorded in the General Commercial Registry (GEMI).",
                //5
                "Corporate events should not be confused with corporate <b>news</b>, which includes any information related to the company (e.g., events, awards).",   
                //6
                "Public Money",
                //7
                "Approvals",
                //8
                "Before any action is taken for the execution of a public project, approval is required from the responsible authority of the Greek state. The approval decisions are published on the DIAVGEIA platform and contain detailed information about the corresponding projects and public expenditures.",
                //9
                "Payments",
                //10
                "In addition to the approvals of public expenditures, the official documents of the corresponding payments for these projects are also made public. The relevant decision is published on the DIAVGEIA platform and includes similar information to that contained in the approval documents mentioned above.",
                //11
                "Assignments",
                //12
                "Moreover, the project assignment documents are an integral part of the public process and contain information regarding the public authorities and the contractors of the project. As with the previous two cases, the official documents published on the DIAVGEIA platform regarding project assignments contain exactly the same information as those of the approvals and payments.",
                //13
                "Awards",
                //14
                "Another stage in the process of public project tenders by the Greek state is the awarding of the project, allowing its execution to proceed. The official documents published on the DIAVGEIA platform contain the same information as the assignment documents.",
                //15
                "NSRF",
                //16
                "The National Strategic Reference Framework (NSRF) is a key strategic plan for the development of the country with the assistance of significant resources from the European Structural and Investment Funds of the European Union. Information regarding NSRF programs is posted on the website of the Ministry of Development and Investments of the Greek Government.",
                //17
                "Company Evaluation",
                //18
                "Market Ranking",
                //19
                "The company's market ranking is calculated based on the company's sales in relation to the total sales of the market.",
                //20
                "Trend",
                //21
                "This indicator pertains to the financial trend of the company under examination, based on its sales. To calculate the indicator, it is necessary to have sales figures for two (2) consecutive years. Additionally, to satisfy the same condition, the most recent year must not be more than two (2) years old from the current year.",
                //22
                "Newly established companies that do not have sales figures and thus cannot have the indicator calculated are marked as <b>«in development.»</b>",
                //23
                "Companies that do not meet the criteria for calculating the indicator mentioned above are marked as <b>«not evaluated»</b>.",   
                //24
                "Statutory Audit Evaluation",
                //25
                "Linked Business, using AI technologies, automatically processes and categorizes the announcements of the statutory audit into a ten (10) tier evaluation scale.",   
                //26
                "Market Pulse",
                //27
                "Establishment Rate",
                //28
                "The establishment rate per year is calculated by the number of new establishments during the reference year relative to the total number of active companies up to that year. The reference year is defined as the most recently completed financial year.",
                //29
                "3 or 5 Year Survival Rate",
                //30
                "According to Eurostat*, the survival rate of newly established companies for a specific reference period is defined as the number of companies established in the year marking the start of the reference period that survived until the year marking the end of the reference period, relative to the total number of companies established in the year marking the start of the reference period.", 
                //31
                "Market Concentration Rate",
                //32
                "The market concentration rate is calculated based on the Herfindahl-Hirschman Index (HHI). The index is used to measure the level of market concentration in a given sector. It is a widely accepted indicator of market competition and is well-known. Linked Business uses the classification of concentration levels as employed by the European Commission according to the Council Regulations on the control of concentrations between undertakings (Official Journal of the European Union C 31/03)",
                //33
                "Data by Public Company Declaration",
                //34
                "All primary data managed by Linked Business are sourced from public open sources and therefore either constitute a public declaration by the company (announcements posted on the General Commercial Registry - GEMI) or represent decisions and actions by government and administrative bodies available on websites such as DIAVGEIA. Linked Business bears no responsibility for data that have been incorrectly declared, announced, or posted, or that contain omissions.",
                //35
                "Published Financial Results",
                //36
                "All variables that require the use of financial results for their calculation in the reference year (current financial year - 1) take into account the number of companies that have published financial results on the General Commercial Registry (GEMI) for the current financial year - 1, up to the time the report is produced. This obligation to publish financial results primarily applies to public limited companies (S.A.), limited liability companies (Ltd.), private capital companies (IKE), and partnerships limited by shares. The deadline for publishing financial results on GEMI for any financial year is set for September 30th of the following year, except for the year 2022, where the deadline for publishing financial results on GEMI was set for November 19th.",
                //37
                "Disclaimer",
                //38
                "This report contains data sourced from open public sources and is provided 'as is' under a specific usage license, without any further processing by Linked Business Single-Member S.A. This report does not constitute business advice in any way, and Linked Business assumes no responsibility regarding how the user utilizes it.",
                //39
                "Are you a credit controller or an asset tracer? Are you trying to expand your customer base? Or are you striving to orchestrate your CRM data and extract knowledge out of them?",
                //40
                "Linked Business provides end-to-end solutions for your critical business needs.",
                //41
                "Understand Past & Predict Future Performance",
                //42
                "Linked Business provides know-your-customer (KYC) services to public or private bodies and organizations aiming to <b>calculate and reduce risk.</b> This is especially important for those organizations that manage and invest funds to support public or private projects. In this instance, Linked Business services are of paramount importance in delivering to the interested parties either lagging indicators for contractors seeking to undertake public or private projects and/or leading indicators.",
                //43
                "Find out more at: ",
                //44
                "Sustainable Growth with the Richest B2B Lead Generation",
                //45
                "Sales leads can be much more than plain contacts in a spreadsheet. Linked Business enriches sales leads far beyond contact details and <b>provides sales qualified leads (SQL) using the most accurate market segmentation models.</b> Information includes complete commercial activity, contact details, address and map location, financial results range, company stakeholders, financial health flags, and hidden relationships among companies and persons in the business network. This smart dataset allows B2B companies to laser-target the ideal prospects, uncover opportunities such as newly established companies, upsell and cross-sell opportunities and gain deep knowledge and insights about the market.",
                //46
                "Instant & Flawless Onboarding Automation",
                //47
                "LB Digital Onboarding is a horizontal service for Linked Business Suite of Tools, that simplifies and accelerates the traditional onboarding for new clients, keeping process costs low and CRM data quality high.",
                //48
                "The main advantages of using digital onboarding, are:",
                //49
                "Simple-to-no Administration",
                //50
                "Reduced Costs",
                //51
                "Consistency",
                //52
                "Data Quality",
                //53
                "Compliance",
                //54
                "Multi-dimensional Knowledge",
                //55
                "Ensure accurate, complete, consistent, secure, and up-todate data!",
                //56
                "It is a well-known truth about bad data that it costs 1 to prevent, 10 to correct, and 100 to deal with the consequences. LB Master Data Governance is an end-toend service that extends from data cleansing and augmentation applications to the definition and modeling of policies, rules, and processes that ensure high data quality throughout the entire lifecycle of business data.",
                //57
                "The service is provided as custom-made project that deals with bad business data inside enterprise systems. Typical cases of such data include:",
                //58
                "Unverified records",
                //59
                "Duplicate records",
                //60
                "Corrupted records",
                //61
                "Outdated records",
                //62
                "Expired records"
            ];
            $trans_content = [
                //0
                "The term is displayed as it is recorded in the source. No updated information has been published so far.",
                //1
                "The field relates to the participation of the management members in third-party companies. The number inside the parentheses refers to active companies, while the number outside the parentheses refers to the total number of companies.",
                //2
                "There is no start date for the term available in the source.",
                //3
                "There is no end date for the term available in the source.",
                //4
                "In this edition, ownership information is displayed only for companies that have been established through the One-Stop Service.",
                //5
                "The ownership table displays companies that have been established through the One-Stop Service. The information in the ownership table has been revised either in terms of composition, contributions, or participation percentage, based on the most recent amendment of the articles of association (or related announcements) and the information contained therein each time.",
                //6
                "The company may be burdened with adverse events other than debts, fines, and auctions, which are taken into account in the calculation of the transaction behavior index.",
                //7
                "The published financial statements are prepared in accordance with Greek Accounting Standards (ELP)."
            ];
        }
        
        $json_data = [
            "lang" => $lang, "langimg" => $lang_img,
            "legal_name" => $company_name,
            "brand_name" => "-",
            "company_birth_date_title" => $company_birth_date_title,
            "company_birth_date" => $foundDate,
            "summary_title" => $summary_title, "events_title" => $events_title, "public_money_title" => $sum_money_title, "credit_score_page" => $credit_score_page, 
            "opinions_title" => $opinions_title, "unfavorable_page_title" => $unfavorable_page_title, "business_tree_title" => $business_tree_title, "market_section_title" => $market_section_title,
            "basic_company_info_table" => $basic_company_info_table, "important_events" => $important_events, "summary_performance_title" => $performance_title,
            "summary_adverse_title" => $summary_adverse_title, "summary_evaluation_title"=>$summary_evaluation_title, "demographics_title" => $demographics_title, "announcement_count_title" => $announcement_count_title,
            "company_events_list_title" => $company_events_list_title, "public_money_summary" => $public_money_summary, "pm_plot_title" => $pm_plot_title,
            "pm_pie_plot_title" => $pm_pie_plot_title, "last_update_page" => $last_update_page, "credit_score_title" => $credit_score_title, "market_segmentation_title" => $market_segmentation_title,
            "market_trends_title" => $market_trends_title, "behavior_assessment_index_title" => $behavior_assessment_index_title, "exchange_behavior_title" => $exchange_behavior_title,
            "market_concentration_title" => $market_concentration_title, "table_asterisk_pulse" => $table_asterisk_pulse, "table_asterisk_ownership" => $table_asterisk_ownership,
            "vat_number" => $vat, "vat_status" => $vatStatus,
            "company_number" => "<a href=\"https://www.businessregistry.gr/publicity/show/" . $companyGemi . "\">" . $companyGemi . "</a>",
            "gemh_page" => "https://www.businessregistry.gr/publicity/show/" . $companyGemi, "gemh_status" => $gemiStatus,
            "chamber" => "-",
            "market_trbc" => $market,
            "main_activity" => $companyActivity,
            "address" => $companyAddress, "city" => $companyCity, "pc" => $cityCode, "locality" => $area, "phone" => $companyPhone,
            "email" => $email, "web" => $website, "general_manager" => [],
            "summary_performance_table" => $performance_table,
            "summary_public_money_title" => $sum_money_title, "summary_public_money_table" => $sum_money_table,
            "summary_adverse_table" => $adverse_table,
            "credit_score" => $credit_score,
            "market_segmentation" => $mseg, "market_segmentation_last_year" => $mseg_last_year,
            "market_trends" => $mtrends,
            "market_concentration" => $mconc,
            "company_events_list_table" => $event_table, "xan" => $x,
            "public_money_table" => $money_table, "xmon" => $xmon,
            // "public_money_page_footnote" => $footnote,  
            "pm_year_plot" => "pm1.png",
            "pm_pie_plot" => "pm2.png",
            "announcements_plot" => "chart-events.png",
            "unfav_plot" => "unfav.png",
            "file1" => $urlEvents, "file2" => $urlpm1, "file3" => $urlpm2, "file4" => $urlUnfav,
            // "credit_score" => [], 
            // "business_tree_title" => $business_tree_title, 
            "board_title" => $management_title,
            "board_table" => $management,
            "ownership_title" => $ownership_title,
            "ownership_table" => $ownership, "xbus" => $xbus,
            "ownership_table_footnote" => $ownership_footnote,
            "market_info_title" => $market_info_title, "market_info_table" => $market_info_table,
            "market_info_title2" => $market_info_title2,
            "market_info_table2" => $market_info_table2,
            "xmar" => $xmar,
            "public_money_page_footnote" => $public_money_page_footnote,
            "public_money_market_title" => $public_money_market_title,
            "public_money_market_table" => $public_money_market_table,
            "adverse_events_title" => $adverse_events_title,
            "adverse_events_table" => $adverse_events_table,
            "demographics" => $demographics,
            "behavior_assessment_index" => $behavior_assessment_index, "behavior_assessment_index_last_year" => $behavior_assessment_index_last_year,
            "results_title" => $results_title,
            "basic_variables_table" => $basic_variables_table, "xres" => $xres,
            "indices_table" => $indices_table, "xcre" => $xcre,
            "performance_title" => $performance_title2,
            "performance_table" => $performance_table2,
            "sworn_audit_text" => $sworn_audit_text,
            "assessment_index" => $assessment_index, "xswo" => $xswo, "opinions_summary" => $opinions_summary, "opinions_full" => $opinions_full,
            "unfavorable" => null, "finestable" => $finestable, "DFTable" => $df_table, "DFTitle" => $dftitle, "AucTitle" => $auctti, "AucTable" => $auctions_table,
            "AucDetailTable" => $auctions_details_table, "AucDetailTitle" => $auc_detail_title, "xunf" => $xunf, "AucDetailTable2" => $auctions_details_table2, "AucDetailTitle2" => $auc_detail_title2,
            "blocksPack" => $blocksPack, "packageReport" => $bpack, "oneGlanceCards" => $oneglancecards, "foot" => $foot, "tcontent" => $trans_content
        ];

        
        // file_put_contents("data".$vat.$bpack.".json", json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    }

    public function sort_rating($val, $s = "asc")
    {
        if ($s == "desc") {
            $year = 0;
            $ret = array();
            $val2 = $val;
            foreach ($val as $k => $v) {
                foreach ($val2 as $k2 => $v2) {
                    if ($v2["year"] > $year) {
                        $i = $k2;
                        $year = $v2["year"];
                    }
                }
                $year = 0;
                array_push($ret, $val2[$i]);
                unset($val2[$i]);
            }
        } elseif ($s == "asc") {
            $year = 5000;
            $ret = array();
            $val2 = $val;
            foreach ($val as $k => $v) {
                foreach ($val2 as $k2 => $v2) {
                    if ($v2["year"] < $year) {
                        $i = $k2;
                        $year = $v2["year"];
                    }
                }
                $year = 5000;
                array_push($ret, $val2[$i]);
                unset($val2[$i]);
            }
        }
        return $ret;
    }


    public function ChosePack($bpack) {
        if (str_contains($bpack, "Asset") && str_contains($bpack, "Tracer")) {
            $blocksPack = [
                "Basic" => true,
                "Performance" => false,
                "BusinessNetwork" => true,
                "Unfavorable" => true,
                "Events" => true,
                "News" => false,
                "Money" => true,
                "Credit" => false,
                "SwornAudit" => false,
                "Market" => false
            ];
            $oneglancecards = 3;

        } elseif (str_contains($bpack, "KYC") && str_contains($bpack, "Insights")) {
            $blocksPack = [
                "Basic" => true,
                "Performance" => false,
                "BusinessNetwork" => true,
                "Unfavorable" => false,
                "Events" => true,
                "News" => false,
                "Money" => true,
                "Credit" => false,
                "SwornAudit" => false,
                "Market" => false
            ];
            $oneglancecards = 2;

        } else { // Pro, Pro 4, Pro 5, Master etc
            $blocksPack = [
                "Basic" => true,
                "Performance" => true,
                "BusinessNetwork" => true,
                "Unfavorable" => true,
                "Events" => true,
                "News" => true,
                "Money" => true,
                "Credit" => true,
                "SwornAudit" => true,
                "Market" => true
            ];

            $oneglancecards = 1;
        }

        return array($blocksPack,$oneglancecards);
    }
}