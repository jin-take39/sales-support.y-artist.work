<?php
namespace App\Plugins\DecisionEvent;

use Exceedone\Exment\Services\Plugin\PluginEventBase;
use Exceedone\Exment\Model\CustomTable;
class Plugin extends PluginEventBase
{
    /**
     * Plugin Trigger
     */
    public function execute()
    {
        $reDataList = array();

        // 決定表のモデル取得
        $decision = CustomTable::getEloquent('decision_table')->getValueModel($this->custom_value->parent_id);

        // 計算
        if($this->custom_table->table_name == "deposit"){

            // 決定表の親IDで見積・請求取得
            $dataList = CustomTable::getEloquent('deposit')->getValueModel()->query()->where('parent_id',$this->custom_value->parent_id)->get()->toArray();

            // 見積・請求のサマリー
            $reDataList = $this->calSummary($this->custom_table->table_name,$dataList);
    
            $decision->setValueStrictly(
                [
                    'total_amount' => $reDataList['deposit']['total_amount'],
                    'total_tax' => $reDataList['deposit']['total_tax'],
                    'total_deposit' => $reDataList['deposit']['total_deposit'],
                ]
            );
            $decision->save();

        }else if($this->custom_table->table_name == "payment"){

            \Log::debug($this->custom_table->table_name);
            // 決定表の親IDで支払い取得
            $dataList = CustomTable::getEloquent('payment')->getValueModel()->query()->where('parent_id',$this->custom_value->parent_id)->get()->toArray();

            // 支払のサマリー
            $reDataList = $this->calSummary($this->custom_table->table_name,$dataList);

            $decision->setValueStrictly(
                [
                    'total_tax_exc_purchase' => $reDataList['payment']['total_tax_exc_purchase'],
                    'total_payment_tax' => $reDataList['payment']['total_payment_tax'],
                    'total_tax_in_purchase' => $reDataList['payment']['total_tax_in_purchase'],
                    'total_source_tax' => $reDataList['payment']['total_source_tax'],
                    'total_transport_exp' => $reDataList['payment']['total_transport_exp'],
                    'total_payment_amount' => $reDataList['payment']['total_payment_amount'],
                ]
            );
            $decision->save();
        }

        // 利益額（売上合計 - 仕入れ額合計（税抜）- 交通費）
        // 利益率（利益額÷売上合計）
        $decisionDataList = CustomTable::getEloquent('decision_table')->getValueModel()->query()->where('id',$this->custom_value->parent_id)->get()->first()->toArray();
        $decision->setValueStrictly([
            'total_profit_amount' => $decisionDataList['value']['total_amount']-$decisionDataList['value']['total_tax_exc_purchase']-$decisionDataList['value']['total_transport_exp'],
            'total_profit_rate' => ($decisionDataList['value']['total_amount']-$decisionDataList['value']['total_tax_exc_purchase']-$decisionDataList['value']['total_transport_exp']) / $decisionDataList['value']['total_amount'],
        ]);
        $decision->save();

        return true;
    }

    private function calSummary($type,$dataList){

        $reDataList = array();
        $reDataList['deposit']['total_amount'] = 0;                     // 売上合計
        $reDataList['deposit']['total_tax'] = 0;                        // 消費税合計
        $reDataList['deposit']['total_deposit'] = 0;                    // 入金合計
        $reDataList['payment']['total_tax_exc_purchase'] = 0;           // 仕入額合計(税抜)
        $reDataList['payment']['total_payment_tax'] = 0;                // 消費税合計
        $reDataList['payment']['total_tax_in_purchase'] = 0;            // 仕入額合計(税込)
        $reDataList['payment']['total_source_tax'] = 0;                 // 源泉税合計
        $reDataList['payment']['total_transport_exp'] = 0;              // 交通費合計
        $reDataList['payment']['total_payment_amount'] = 0;             // 支払額

        foreach($dataList as $data){

            // 見積・請求
            if($type == "deposit"){

                // 売上合計 = 小合計＋営業管理費（中間計）－割引額
                $reDataList['deposit']['total_amount'] += $data["value"]["tax_excluded_amount"];
                
                // 消費税合計 = 消費税summary
                $reDataList['deposit']['total_tax'] += $data["value"]["tax"];

                // 入金額合計 = 総計summary
                $reDataList['deposit']['total_deposit'] += $data["value"]["deposit_amount"];
            }else{
    
                // 仕入額合計（支払いの、仕入額（税抜））
                $reDataList['payment']['total_tax_exc_purchase'] += $data["value"]["tax_excluded_purchase"];
                
                // 消費税合計（支払いの、消費税）
                $reDataList['payment']['total_payment_tax'] += $data["value"]["tax"];
                
                // 仕入合計額（支払いの、仕入額（税込））
                $reDataList['payment']['total_tax_in_purchase'] += $data["value"]["tax_includ_purchase"];

                // 源泉税合計（支払いの、源泉税）
                $reDataList['payment']['total_source_tax'] += $data["value"]["source_tax"];
                
                // 交通費合計（決定表の交通費）
                $reDataList['payment']['total_transport_exp'] += $data["value"]["transport_exp"];
                
                // 支払額（支払いの、支払額）
                $reDataList['payment']['total_payment_amount'] += $data["value"]["payment_amount"];
            }
        }

        return $reDataList;
    }
}