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

}
