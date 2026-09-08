<?php
namespace WPDSQL\MySQL\Translation;

/** MySQL calendar rules expressed with PostgreSQL date arithmetic. */
final class Calendar {
    public static function sql(string $name,string $date,int $mode=0): string {
        // A derived column evaluates/binds the caller's expression once, including NULL.
        $source='(SELECT CAST('.$date.' AS date) AS d) AS _wpd_date';
        $zero="d = DATE '0001-01-01'";
        $unit=match($name){'DAYOFYEAR'=>'EXTRACT(DOY FROM d)','DAYOFWEEK'=>'EXTRACT(DOW FROM d)+1','WEEKDAY'=>'EXTRACT(ISODOW FROM d)-1',default=>null};
        if($unit!==null)return '(SELECT CASE WHEN '.$zero.' THEN NULL ELSE CAST('.$unit.' AS integer) END FROM '.$source.')';
        if($mode<0||$mode>7)throw new \RuntimeException('WEEK mode must be between 0 and 7');
        $monday=(bool)($mode&1);$fourDays=in_array($mode,[1,3,4,6],true);
        $first=static function(string $year)use($monday,$fourDays):string{
            $day=$fourDays?'('.$year.'+3)':$year;
            $weekday='CAST(EXTRACT('.($monday?'ISODOW':'DOW').' FROM '.$day.') AS integer)'.($monday?'-1':'');
            return $fourDays?'('.$day.'-('.$weekday.'))':'('.$day.'+MOD(7-('.$weekday.'),7))';
        };
        $source='(SELECT d,CAST(date_trunc(\'year\',d) AS date) AS y FROM '.$source.') AS _wpd_year';
        $source='(SELECT d,'.$first('y').' AS first,'.$first("CAST(y-INTERVAL '1 year' AS date)").' AS previous,'.$first("CAST(y+INTERVAL '1 year' AS date)").' AS next FROM '.$source.') AS _wpd_weeks';
        $number='CAST(FLOOR((d-first)/7.0)+1 AS integer)';
        $result=($mode&2)?'CASE WHEN d<first THEN CAST(FLOOR((d-previous)/7.0)+1 AS integer) WHEN d>=next THEN 1 ELSE '.$number.' END':'CASE WHEN d<first THEN 0 ELSE '.$number.' END';
        return '(SELECT CASE WHEN '.$zero.' THEN NULL ELSE '.$result.' END FROM '.$source.')';
    }
}
