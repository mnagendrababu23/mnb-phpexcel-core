<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile};
use Mnb\PHPExcel\Cloud\Http\HttpClient;
final class GoogleSheetsProvider {
 public function __construct(private ?HttpClient $http=null){$this->http??=new HttpClient();}
 private function h(CloudAccount $a):array{return ['Authorization'=>'Bearer '.$a->accessToken()];}
 public function create(CloudAccount $a,string $title,array $sheets=[]):CloudFile{
  $body=['properties'=>['title'=>$title]];
  if($sheets!==[])$body['sheets']=array_map(fn($n)=>['properties'=>['title'=>(string)$n]],array_keys($sheets));
  $d=$this->http->json('POST','https://sheets.googleapis.com/v4/spreadsheets',$this->h($a),$body);
  $id=(string)$d['spreadsheetId'];
  foreach($sheets as $name=>$rows)$this->writeRows($a,$id,(string)$name,$rows);
  return new CloudFile('google-sheets',$id,$title,'application/vnd.google-apps.spreadsheet',null,$d['spreadsheetUrl']??null,null,$d);
 }
 public function writeRows(CloudAccount $a,string $id,string $sheet,iterable $rows):void{
  $values=[];foreach($rows as $row)$values[]=is_array($row)?array_values($row):[$row];
  if($values===[])return;
  $range=rawurlencode("'".str_replace("'","''",$sheet)."'!A1");
  $this->http->json('PUT',"https://sheets.googleapis.com/v4/spreadsheets/".rawurlencode($id)."/values/$range?valueInputOption=RAW",$this->h($a),['range'=>"$sheet!A1",'majorDimension'=>'ROWS','values'=>$values]);
 }
 public function readRows(CloudAccount $a,string $id,string $range='Sheet1!A:ZZ'):array{
  $d=$this->http->json('GET','https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($id).'/values/'.rawurlencode($range),$this->h($a));
  return $d['values']??[];
 }
}