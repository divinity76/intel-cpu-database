<?php
declare(strict_types=1);
//system("echo ".escapeshellarg(".dump")." | sqlite3 ".escapeshellarg('intel_cpu_database.sqlite3')) & die();
$data = json_decode(file_get_contents('databases/intel_cpu_database.json'), true);
$filter_data = function (array $data): array {
    $inner_filter=function(string $str):string{
        return trim(strtr($str, array(
            '®' => '',
            '‡' => '',
            '™' => '',
            '*' => ''
        )));
    };
    $new_arr=array();
    foreach ($data as $id=>$cpu) {
        $new_cpu=array();
        $new_cpu['name']=$inner_filter($cpu['name']);
        unset($cpu['name']);
        foreach($cpu as $category=>$vals){
            foreach($vals as $prop_name=>$prop_val){
                $new_cpu[$inner_filter($category)][$inner_filter($prop_name)]=$inner_filter($prop_val);
            }
        }
        $new_arr[$id]=$new_cpu;
    }
    return $new_arr;
};
$data = $filter_data($data);
unset($filter_data);
$spec_names = function (array $data): array {
    $ret = array();
    foreach ($data as $id => $cpu_data) {
        unset($cpu_data['name']);
        foreach ($cpu_data as $category => $something) {
            foreach ($something as $name => $value) {
                $ret[$name] = true;
            }
        }
    }
    return array_keys($ret);
};
$spec_names = $spec_names($data);
if(file_exists('databases/intel_cpu_database.sqlite3')){
    unlink('databases/intel_cpu_database.sqlite3');
}
$sql_fp=fopen('databases/intel_cpu_database.sql',"wb");
$db3 = new PDO('sqlite:databases/intel_cpu_database.sqlite3', '', '', array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
// SELECT * FROM intel_cpus WHERE id=(SELECT id FROM intel_cpus WHERE category='name' AND value LIKE '9900K' LIMIT 1)
$sql = '
DROP TABLE IF EXISTS `intel_cpus`;
CREATE TABLE IF NOT EXISTS `intel_cpus` (
`id` INTEGER NOT NULL,
`name` STRING NOT NULL,
';
foreach ($spec_names as $spec_name) {
    $sql .= "`{$spec_name}` TEXT NULL DEFAULT NULL,\n";
}
if (!empty($spec_names)) {
    $sql = substr($sql, 0, -strlen(",\n"));
}
$sql .= "\n);\n";
fwrite($sql_fp,$sql);
//var_dump($sql) & die();

$db3->exec($sql);
$db3->beginTransaction();
foreach ($data as $id => $cpu_data) {
    $sql="INSERT INTO `intel_cpus` (`id`,`name`,";
    $name=$cpu_data['name'];
    unset($cpu_data['name']);
    foreach($cpu_data as $category=>$category_data){
        foreach($category_data as $cat_name=>$cat_val){
            $sql.="`{$cat_name}`,";
        }        
    }
    $sql=substr($sql,0,-strlen(","));
    $sql.=") VALUES(";
    $sql.=((int)$id).",".$db3->quote($name).",";
    foreach($cpu_data as $category=>$category_data){
        foreach($category_data as $cat_name=>$cat_val){
            $sql.=$db3->quote($cat_val).",";
        }
    }
    $sql=substr($sql,0,-strlen(","));
    $sql.=");\n";
    fwrite($sql_fp,$sql);
    $db3->query($sql);
}
$db3->commit();
