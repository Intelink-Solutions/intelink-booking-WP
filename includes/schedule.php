<?php
namespace Intelink;
defined('ABSPATH') || exit;
final class Schedule {
    private static array $cache=[];
    public static function reset(): void { self::$cache=[]; }
    public static function services(array $ids): array {
        $ids=array_values(array_unique(array_map('absint',$ids)));
        if (!$ids || count($ids)>20) throw new \InvalidArgumentException('Select a service.');
        if (!Store::settings()['multiple_services'] && count($ids)>1) throw new \InvalidArgumentException('Select one service.');
        $services=[]; foreach ($ids as $id) { $s=Store::get('services',$id); if (!$s || $s['status']!=='active') throw new \InvalidArgumentException('This service is unavailable.'); $config=Store::json($s['config']??'{}'); $s['program_unit']=$config['program_unit']??'single'; $s['program_length']=max(1,(int)($config['program_length']??1)); $services[]=$s; } return $services;
    }
    public static function staff(array $services): array {
        $eligible=null;
        foreach ($services as $s) {
            $ids=array_map('intval',array_column(Store::all('staff_services','service_id=%d',[$s['id']]),'staff_id'));
            if ($ids) $eligible=$eligible===null ? $ids : array_values(array_intersect($eligible,$ids));
        }
        if ($eligible===null) return [0];
        return array_values(array_filter($eligible,fn($id)=> (Store::get('staff',$id)['status']??'')==='active'));
    }
    public static function windows(string $date,string $scope,int $owner): ?array {
        $cacheKey='windows:'.$date.':'.$scope.':'.$owner; if(array_key_exists($cacheKey,self::$cache)) return self::$cache[$cacheKey];
        $blocked=Store::all('blocked_dates','scope=%s AND owner_id=%d AND date_from<=%s AND date_to>=%s',[$scope,$owner,$date,$date]); if ($blocked) return self::$cache[$cacheKey]=[];
        $day=(int)(new \DateTimeImmutable($date,Store::tz()))->format('N');
        $all=Store::all('availability','scope=%s AND owner_id=%d',[$scope,$owner]); if (!$all) return self::$cache[$cacheKey]=null;
        $special=array_values(array_filter($all,fn($r)=>$r['special_date']===$date));
        return self::$cache[$cacheKey]=$special ?: array_values(array_filter($all,fn($r)=>!$r['special_date'] && (int)$r['weekday']===$day));
    }
    public static function inWindow(string $date,int $start,int $end,string $scope,int $owner): bool {
        $windows=self::windows($date,$scope,$owner); if ($windows===null) return $scope!=='business';
        foreach ($windows as $w) {
            $open=(new \DateTimeImmutable($date.' '.$w['opens'],Store::tz()))->getTimestamp(); $close=(new \DateTimeImmutable($date.' '.$w['closes'],Store::tz()))->getTimestamp();
            if ($start<$open || $end>$close) continue;
            $ok=true; foreach (Store::json($w['breaks']) as $break) { if (!is_array($break) || count($break)!==2) continue; $a=(new \DateTimeImmutable($date.' '.$break[0],Store::tz()))->getTimestamp(); $b=(new \DateTimeImmutable($date.' '.$break[1],Store::tz()))->getTimestamp(); if ($start<$b && $end>$a) $ok=false; }
            if ($ok) return true;
        } return false;
    }
    public static function peak(array $intervals,int $start,int $end): int {
        $points=[]; foreach ($intervals as $i) { $a=max($start,strtotime($i['busy_start'].' UTC')); $b=min($end,strtotime($i['busy_end'].' UTC')); if ($a<$b) { $points[]=[$a,1]; $points[]=[$b,-1]; } }
        usort($points,fn($a,$b)=>$a[0]<=>$b[0] ?: $a[1]<=>$b[1]); $n=0;$max=0; foreach ($points as $p) { $n+=$p[1]; $max=max($max,$n); } return $max;
    }
    public static function check(array $services,int $start,int $staff=0,int $exclude=0,bool $enforceNotice=true): ?array {
        $cfg=Store::settings(); $now=time(); if ((!$enforceNotice && $start<$now) || ($enforceNotice && $start<$now+(int)$cfg['min_notice']*60) || $start>$now+(int)$cfg['max_days']*86400) return null;
        $date=(new \DateTimeImmutable('@'.$start))->setTimezone(Store::tz())->format('Y-m-d');
        $midnight=(new \DateTimeImmutable($date.' 00:00',Store::tz()))->getTimestamp(); if($enforceNotice && ($start-$midnight)%(max(5,(int)$cfg['slot_interval'])*60)!==0) return null;
        $duration=array_sum(array_column($services,'duration')); $end=$start+$duration*60;
        $before=max(array_column($services,'buffer_before'))*60; $after=max(array_column($services,'buffer_after'))*60;
        $a=$start-$before; $b=$end+$after;
        if (!self::inWindow($date,$a,$b,'business',0)) return null;
        foreach ($services as $s) if (!self::inWindow($date,$a,$b,'service',(int)$s['id'])) return null;
        $eligible=self::staff($services); if ($staff) $eligible=array_values(array_intersect($eligible,[$staff]));
        $busyKey='busy:'.$date.':'.$exclude;
        $busy=self::$cache[$busyKey]??=Store::all('appointments',"id<>%d AND status IN ('pending','awaiting_payment','confirmed','completed','no_show') AND (expires_at IS NULL OR expires_at>%s) AND busy_start<%s AND busy_end>%s",[$exclude,Store::now(),gmdate('Y-m-d H:i:s',$midnight+172800),gmdate('Y-m-d H:i:s',$midnight-86400)]);
        if (self::peak($busy,$a,$b)>=(int)$cfg['capacity']) return null;
        foreach ($services as $s) { $matching=array_filter($busy,fn($r)=>in_array((int)$s['id'],array_map('intval',array_column(Store::json($r['snapshot'])['services']??[],'id')))); if (self::peak($matching,$a,$b)>=(int)$s['capacity']) return null; }
        foreach ($eligible as $id) {
            if ($id && !self::inWindow($date,$a,$b,'staff',$id)) continue;
            if ($id && self::peak(array_filter($busy,fn($r)=>(int)$r['staff_id']===$id),$a,$b)>=(int)Store::get('staff',$id)['capacity']) continue;
            return ['starts_at'=>gmdate('Y-m-d H:i:s',$start),'ends_at'=>gmdate('Y-m-d H:i:s',$end),'busy_start'=>gmdate('Y-m-d H:i:s',$a),'busy_end'=>gmdate('Y-m-d H:i:s',$b),'staff_id'=>$id];
        } return null;
    }
    public static function dates(array $ids,string $month,int $staff=0): array {
        if(!preg_match('/^\d{4}-\d{2}$/',$month)) throw new \InvalidArgumentException('Invalid month.');
        $base=new \DateTimeImmutable($month.'-01',Store::tz()); if($base->format('Y-m')!==$month) throw new \InvalidArgumentException('Invalid month.');
        $services=self::services($ids);$result=[];$step=max(5,(int)Store::settings()['slot_interval'])*60;
        for($day=1;$day<=(int)$base->format('t');$day++) { $date=$base->setDate((int)$base->format('Y'),(int)$base->format('m'),$day); $key=$date->format('Y-m-d');$result[$key]=false;
            for($t=$date->getTimestamp();$t<$date->modify('+1 day')->getTimestamp();$t+=$step) if(self::check($services,$t,$staff)) { $result[$key]=true;break; }
        } return $result;
    }
    public static function slots(array $ids,string $date,int $staff=0,int $exclude=0): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new \InvalidArgumentException('Invalid date.');
        $services=self::services($ids); $base=new \DateTimeImmutable($date.' 00:00',Store::tz()); if ($base->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Invalid date.');
        $slots=[]; $step=max(5,(int)Store::settings()['slot_interval'])*60;
        for ($t=$base->getTimestamp();$t<$base->modify('+1 day')->getTimestamp();$t+=$step) if ($slot=self::check($services,$t,$staff,$exclude)) $slots[]=['value'=>gmdate('Y-m-d\TH:i:s\Z',$t),'label'=>wp_date(Store::settings()['time_format'],$t,Store::tz()),'staff_id'=>$slot['staff_id']];
        return $slots;
    }
}
