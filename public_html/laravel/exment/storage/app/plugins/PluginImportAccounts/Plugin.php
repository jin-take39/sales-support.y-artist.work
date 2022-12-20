<?php
namespace App\Plugins\PluginImportAccounts;

use Exceedone\Exment\Services\Plugin\PluginImportBase;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Plugin extends PluginImportBase{
    /**
     * execute
     */
    public function execute() {
        
        \Log::info("Start Plugin Import ...");
        $startPosition = 2;
        $account = array();
        $contact = array();
        $number = 0;
        $company = "";

        // Excel Object 作成
        $path = $this->file->getRealPath();

        $reader = $this->createReader();
        $spreadsheet = $reader->load($path);

        // Sheet1のB4セルの内容で契約テーブルを読み込みます
        $sheet = $spreadsheet->getSheetByName('インポート');
        
        // Excelデータのループ
        for($i = $startPosition; $i <= 5000 + $startPosition; $i++){

            // Noがない場合は終了
            $number = getCellValue("A$i", $sheet, true);
            if (!isset($number)) break;

            // 会社名がない場合、スキップ
            $company = getCellValue("B$i", $sheet, true);
            if (!isset($company)) continue;

            $account = [
                "value->company_name" => getCellValue("B$i", $sheet, true)
                ,"value->company_tel" => getCellValue("I$i", $sheet, true)
                ,"value->company_web" => getCellValue("N$i", $sheet, true)
                ,"value->company_class" => "その他"
                ,"value->company_industry" => "その他"
            ];

            // preg_match("/(.*?[都道府県])/u",  getCellValue("G$i", $sheet, true), $maches,PREG_OFFSET_CAPTURE);
            preg_match("/(.*?[都道府県])(.*?)$/u", getCellValue("G$i", $sheet, true), $matches);
            // \Log::debug($matches);
            $contact = [
                "value->customer_name" => getCellValue("C$i", $sheet, true)
                ,"value->customer_name_kana" => getCellValue("D$i", $sheet, true)
                ,"value->department" => getCellValue("E$i", $sheet, true)
                ,"value->officer" => getCellValue("F$i", $sheet, true)
                ,"value->prefectures" => isset($matches[1]) ? $matches[1] : ""
                ,"value->address" => isset($matches[2]) ? $matches[2] : getCellValue("G$i", $sheet, true)
                ,"value->postal_code" => getCellValue("H$i", $sheet, true)
                ,"value->representative_tel" => getCellValue("I$i", $sheet, true)
                ,"value->extension_tel" => getCellValue("J$i", $sheet, true)
                ,"value->fax" => getCellValue("M$i", $sheet, true)
                ,"value->mobile" => getCellValue("K$i", $sheet, true)
                ,"value->mail" => getCellValue("L$i", $sheet, true)
            ];

            // 企業・個人登録処理
            $this->register($i,$account,$contact);

        }

        \Log::info("End Plugin Import ...");
        return true;
    }

    /**
     * create reader
     */
    protected function createReader()
    {
        return IOFactory::createReader('Xlsx');
    }

    /**
     * 企業・個人登録処理
     */
    private function register($row,$account,$contact){

        // 企業情報取得
        $data = getModelName("customer-company")::where("value->company_name",$account["value->company_name"])->get();

        if($data->count() == 1){

            \Log::warning("[Plugin Import Account Contact]" . $row ."行：企業情報->「" . $account["value->company_name"] . "」は既に登録されています。");

            // 企業ID設定
            $contact["value->company_name"] = $data->first()->id;

            // 個人情報登録処理
            $this->contact($row,$account,$contact,$data->first()->id);

        }else if($data->count() >= 2){

            \Log::critical("[Plugin Import Account Contact]" . $row ."行：企業情報->「" . $account["value->company_name"] . "」は既に2件以上登録されています。");

        }else{

            // 企業情報登録
            $record = getModelName("customer-company")::create($account);

            \Log::info("[Plugin Import Account Contact]" . $row ."行：企業情報->「" . $account["value->company_name"] . "」が新規で登録されました。");

            // 企業ID設定
            $contact["value->company_name"] = $record->id;

            // 個人情報登録処理
            $this->contact($row,$account,$contact,$record->id);
        }
    }

    /**
     * 個人登録処理
     */
    private function contact($row,$account,$contact,$account_id){

        // 個人情報取得
        $data = getModelName("customer")::where("value->customer_name",$contact["value->customer_name"])
        ->where("value->company_name",$account_id)
        ->get();

        if($data->count()==0){
            
            \Log::info("[Plugin Import Account Contact]" . $row ."行：顧客担当者情報->「" . $contact["value->customer_name"] . "」が新規で登録されました。");

            // 顧客担当者情報登録
            getModelName("customer")::create($contact);

        }else if($data->count()==1){

            // 企業に紐付く個人が1件の場合、部署＋役職で再検索する。
            $contactObj = getModelName("customer")::where("value->customer_name",$contact["value->customer_name"])
            ->where("value->company_name",$account_id)
            ->where("value->department",$contact["value->department"])
            ->where("value->officer",$contact["value->officer"])
            ->get();

            // 検索結果が0件の場合、以前の部署　以前の役職を現部署、役職に置き換えてUpdate
            if($contactObj->count() == 0){
                getModelName("customer")::where("value->customer_name",$contact["value->customer_name"])
                ->where("value->company_name",$account_id)->update(
                    [
                        "value->department" => $contact["value->department"]
                        ,"value->customer_name_kana" => $contact["value->customer_name_kana"]
                        ,"value->officer" => $contact["value->officer"]
                        ,"value->before_department" => $data->first()->value["department"]
                        ,"value->before_position" => $data->first()->value["officer"]
                        ,"value->prefectures" =>  $contact["value->prefectures"]
                        ,"value->address" =>  $contact["value->address"]
                        ,"value->postal_code" =>  $contact["value->postal_code"]
                        ,"value->representative_tel" =>  $contact["value->representative_tel"]
                        ,"value->extension_tel" =>  $contact["value->extension_tel"]
                        ,"value->fax" =>  $contact["value->fax"]
                        ,"value->mobile" =>  $contact["value->mobile"]
                        ,"value->mail" =>  $contact["value->mail"]
                    ]
                );
                \Log::info("[Plugin Import Account Contact]" . $row ."行：顧客担当者情報->「" . $contact["value->customer_name"] . "」の部署＋役職が更新されました。");
            }else{

                // 検索結果が1件ある場合、部署＋役職以外をUpdate
                getModelName("customer")::where("value->customer_name",$contact["value->customer_name"])
                ->where("value->company_name",$account_id)->update(
                    [
                        "value->prefectures" =>  $contact["value->prefectures"]
                        ,"value->customer_name_kana" => $contact["value->customer_name_kana"]
                        ,"value->address" =>  $contact["value->address"]
                        ,"value->postal_code" =>  $contact["value->postal_code"]
                        ,"value->representative_tel" =>  $contact["value->representative_tel"]
                        ,"value->extension_tel" =>  $contact["value->extension_tel"]
                        ,"value->fax" =>  $contact["value->fax"]
                        ,"value->mobile" =>  $contact["value->mobile"]
                        ,"value->mail" =>  $contact["value->mail"]
                    ]
                );
                \Log::info("[Plugin Import Account Contact]" . $row ."行：顧客担当者情報->「" . $contact["value->customer_name"] . "」の部署＋役職以外が更新されました。");
            }
        }else{
            \Log::critical("[Plugin Import Account Contact]" . $row ."行：顧客担当者情報->「" . $contact["value->customer_name"] . "」は2件以上登録されているためスキップします。");
        }
    }
}
