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
