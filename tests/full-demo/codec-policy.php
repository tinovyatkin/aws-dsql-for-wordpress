<?php
require dirname(__DIR__,2).'/pg4wp/dsql/class-dsql-value-codec.php';
$cases=['plain',"private\0property 🌍",DSQL_Value_Codec::PREFIX.'literal',DSQL_Value_Codec::encode("inner\0value")];
foreach($cases as $value)if(DSQL_Value_Codec::decode(DSQL_Value_Codec::encode($value))!==$value)throw new RuntimeException('Codec round trip failed');
try{DSQL_Value_Codec::decode(DSQL_Value_Codec::PREFIX.str_repeat('0',64).':YWJj');throw new LogicException('Corruption accepted');}catch(RuntimeException $expected){}
try{DSQL_Value_Codec::encode("\xff");throw new LogicException('Invalid UTF-8 accepted');}catch(RuntimeException $expected){}
echo "PASS text codec: NUL, Unicode, prefix collision, nested framing, corruption, invalid UTF-8\n";
