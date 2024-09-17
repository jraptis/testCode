<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WebPortalDevController extends Controller
{    
   
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
        } 
    }

}
