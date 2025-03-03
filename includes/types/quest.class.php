<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


class QuestList extends BaseType
{
    public static   $type      = Type::QUEST;
    public static   $brickFile = 'quest';
    public static   $dataTable = '?_quests';

    public          $requires  = [];
    public          $rewards   = [];
    public          $choices   = [];

    protected       $queryBase = 'SELECT q.*, q.id AS ARRAY_KEY FROM ?_quests q';
    protected       $queryOpts = array(
                        'q'   => [],
                        'rsc' => ['j' => '?_spell rsc ON q.rewardSpellCast = rsc.id'],      // limit rewardSpellCasts
                        'qse' => ['j' => '?_quests_startend qse ON q.id = qse.questId', 's' => ', qse.method'],    // groupConcat..?
                        'e'   => ['j' => ['?_events e ON e.id = `q`.eventId', true], 's' => ', e.holidayId']
                    );

    public function __construct(array $conditions = [], array $miscData = [])
    {
        parent::__construct($conditions, $miscData);

        // i don't like this very much
        $currencies = DB::Aowow()->selectCol('SELECT id AS ARRAY_KEY, itemId FROM ?_currencies');

        // post processing
        foreach ($this->iterate() as $id => &$_curTpl)
        {
            $_curTpl['cat1'] = $_curTpl['zoneOrSort'];      // should probably be in a method...
            $_curTpl['cat2'] = 0;

            foreach (Game::$questClasses as $k => $arr)
            {
                if (in_array($_curTpl['cat1'], $arr))
                {
                    $_curTpl['cat2'] = $k;
                    break;
                }
            }

            // store requirements
            $requires = [];
            for ($i = 1; $i < 7; $i++)
            {
                if ($_ = $_curTpl['reqItemId'.$i])
                    $requires[Type::ITEM][] = $_;

                if ($i > 4)
                    continue;

                if ($_curTpl['reqNpcOrGo'.$i] > 0)
                    $requires[Type::NPC][] = $_curTpl['reqNpcOrGo'.$i];
                else if ($_curTpl['reqNpcOrGo'.$i] < 0)
                    $requires[Type::OBJECT][] = -$_curTpl['reqNpcOrGo'.$i];

                if ($_ = $_curTpl['reqSourceItemId'.$i])
                    $requires[Type::ITEM][] = $_;
            }
            if ($requires)
                $this->requires[$id] = $requires;

            // store rewards
            $rewards = [];
            $choices = [];

            if ($_ = $_curTpl['rewardTitleId'])
                $rewards[Type::TITLE][] = $_;

            if ($_ = $_curTpl['rewardHonorPoints'])
                $rewards[Type::CURRENCY][104] = $_;

            if ($_ = $_curTpl['rewardArenaPoints'])
                $rewards[Type::CURRENCY][103] = $_;

            for ($i = 1; $i < 7; $i++)
            {
                if ($_ = $_curTpl['rewardChoiceItemId'.$i])
                    $choices[Type::ITEM][$_] = $_curTpl['rewardChoiceItemCount'.$i];

                if ($i > 5)
                    continue;

                if ($_ = $_curTpl['rewardFactionId'.$i])
                    $rewards[Type::FACTION][$_] = $_curTpl['rewardFactionValue'.$i];

                if ($i > 4)
                    continue;

                if ($_ = $_curTpl['rewardItemId'.$i])
                {
                    $qty = $_curTpl['rewardItemCount'.$i];
                    if (in_array($_, $currencies))
                        $rewards[Type::CURRENCY][array_search($_, $currencies)] = $qty;
                    else
                        $rewards[Type::ITEM][$_] = $qty;
                }
            }
            if ($rewards)
                $this->rewards[$id] = $rewards;

            if ($choices)
                $this->choices[$id] = $choices;
        }
    }

    // static use START
    public static function getName($id)
    {
        $n = DB::Aowow()->SelectRow('SELECT name_loc0, name_loc2, name_loc3, name_loc4, name_loc6, name_loc8 FROM ?_quests WHERE id = ?d', $id);
        return Util::localizedString($n, 'name');
    }
    // static use END

    public function isRepeatable()
    {
        return $this->curTpl['flags'] & QUEST_FLAG_REPEATABLE || $this->curTpl['specialFlags'] & QUEST_FLAG_SPECIAL_REPEATABLE;
    }

    public function isDaily()
    {
        if ($this->curTpl['flags'] & QUEST_FLAG_DAILY)
            return 1;

        if ($this->curTpl['flags'] & QUEST_FLAG_WEEKLY)
            return 2;

        if ($this->curTpl['specialFlags'] & QUEST_FLAG_SPECIAL_MONTHLY)
            return 3;

        return 0;
    }

    // using reqPlayerKills and rewardHonor as a crutch .. has TC this even implemented..?
    public function isPvPEnabled()
    {
        return $this->curTpl['reqPlayerKills'] || $this->curTpl['rewardHonorPoints'] || $this->curTpl['rewardArenaPoints'];
    }

    // by TC definition
    public function isSeasonal()
    {
        return in_array($this->getField('zoneOrSortBak'), [-22, -284, -366, -369, -370, -376, -374]) && !$this->isRepeatable();
    }

    public function getSourceData(int $id = 0) : array
    {
        $data = [];

        foreach ($this->iterate() as $__)
        {
            if ($id && $id != $this->id)
                continue;

            $data[$this->id] = array(
                "n"  => $this->getField('name', true),
                "t"  => Type::QUEST,
                "ti" => $this->id,
                "c"  => $this->curTpl['cat1'],
                "c2" => $this->curTpl['cat2']
            );
        }

        return $data;
    }

    public function getSOMData($side = SIDE_BOTH)
    {
        $data = [];

        foreach ($this->iterate() as $__)
        {
            if (!(ChrRace::sideFromMask($this->curTpl['reqRaceMask']) & $side))
                continue;

            [$series, $first] = DB::Aowow()->SelectRow(
                'SELECT IF(prev.id OR cur.nextQuestIdChain, 1, 0) AS "0", IF(prev.id IS NULL AND cur.nextQuestIdChain, 1, 0) AS "1" FROM ?_quests cur LEFT JOIN ?_quests prev ON prev.nextQuestIdChain = cur.id WHERE cur.id = ?d',
                $this->id
            );

            $data[$this->id] = array(
                'level'     => $this->curTpl['level'] < 0 ? MAX_LEVEL : $this->curTpl['level'],
                'name'      => $this->getField('name', true),
                'category'  => $this->curTpl['cat1'],
                'category2' => $this->curTpl['cat2'],
                'series'    => $series,
                'first'     => $first
            );

            if ($this->isDaily())
                $data[$this->id]['daily'] = 1;
        }

        return $data;
    }

    public function getListviewData($extraFactionId = 0)    // i should formulate a propper parameter..
    {
        $data = [];

        foreach ($this->iterate() as $__)
        {
            $data[$this->id] = array(
                'category'  => $this->curTpl['cat1'],
                'category2' => $this->curTpl['cat2'],
                'id'        => $this->id,
                'level'     => $this->curTpl['level'],
                'reqlevel'  => $this->curTpl['minLevel'],
                'name'      => Lang::unescapeUISequences($this->getField('name', true), Lang::FMT_RAW),
                'side'      => ChrRace::sideFromMask($this->curTpl['reqRaceMask']),
                'wflags'    => 0x0,
                'xp'        => $this->curTpl['rewardXP']
            );

            if (!empty($this->rewards[$this->id][Type::CURRENCY]))
                foreach ($this->rewards[$this->id][Type::CURRENCY] as $iId => $qty)
                    $data[$this->id]['currencyrewards'][] = [$iId, $qty];

            if (!empty($this->rewards[$this->id][Type::ITEM]))
                foreach ($this->rewards[$this->id][Type::ITEM] as $iId => $qty)
                    $data[$this->id]['itemrewards'][] = [$iId, $qty];

            if (!empty($this->choices[$this->id][Type::ITEM]))
                foreach ($this->choices[$this->id][Type::ITEM] as $iId => $qty)
                    $data[$this->id]['itemchoices'][] = [$iId, $qty];

            if ($_ = $this->curTpl['rewardTitleId'])
                $data[$this->id]['titlereward'] = $_;

            if ($_ = $this->curTpl['type'])
                $data[$this->id]['type'] = $_;

            if ($_ = $this->curTpl['reqClassMask'])
                $data[$this->id]['reqclass'] = $_;

            if ($_ = ($this->curTpl['reqRaceMask'] & ChrRace::MASK_ALL))
                if ((($_ & ChrRace::MASK_ALLIANCE) != ChrRace::MASK_ALLIANCE) && (($_ & ChrRace::MASK_HORDE) != ChrRace::MASK_HORDE))
                    $data[$this->id]['reqrace'] = $_;

            if ($_ = $this->curTpl['rewardOrReqMoney'])
                if ($_ > 0)
                    $data[$this->id]['money'] = $_;

            // todo (med): also get disables
            if ($this->curTpl['flags'] & QUEST_FLAG_UNAVAILABLE)
                $data[$this->id]['historical'] = true;

            // if ($this->isRepeatable())       // dafuque..? says repeatable and is used as 'disabled'..?
                // $data[$this->id]['wflags'] |= QUEST_CU_REPEATABLE;
            if ($this->curTpl['cuFlags'] & (CUSTOM_UNAVAILABLE | CUSTOM_DISABLED))
                $data[$this->id]['wflags'] |= QUEST_CU_REPEATABLE;

            if ($this->curTpl['flags'] & QUEST_FLAG_DAILY)
            {
                $data[$this->id]['wflags'] |= QUEST_CU_DAILY;
                $data[$this->id]['daily'] = true;
            }

            if ($this->curTpl['flags'] & QUEST_FLAG_WEEKLY)
            {
                $data[$this->id]['wflags'] |= QUEST_CU_WEEKLY;
                $data[$this->id]['weekly'] = true;
            }

            if ($this->isSeasonal())
                $data[$this->id]['wflags'] |= QUEST_CU_SEASONAL;

            if ($this->curTpl['flags'] & QUEST_FLAG_AUTO_REWARDED)  // not shown in log
                $data[$this->id]['wflags'] |= QUEST_CU_SKIP_LOG;

            if ($this->curTpl['flags'] & QUEST_FLAG_AUTO_ACCEPT)    // self-explanatory
                $data[$this->id]['wflags'] |= QUEST_CU_AUTO_ACCEPT;

            if ($this->isPvPEnabled())                              // not sure why this flag also requires auto-accept to be set
                $data[$this->id]['wflags'] |= (QUEST_CU_AUTO_ACCEPT | QUEST_CU_PVP_ENABLED);

            $data[$this->id]['reprewards'] = [];
            for ($i = 1; $i < 6; $i++)
            {
                $foo = $this->curTpl['rewardFactionId'.$i];
                $bar = $this->curTpl['rewardFactionValue'.$i];
                if ($foo && $bar)
                {
                    $data[$this->id]['reprewards'][] = [$foo, $bar];

                    if ($extraFactionId == $foo)
                        $data[$this->id]['reputation'] = $bar;
                }
            }
        }

        return $data;
    }

    public function parseText($type = 'objectives', $jsEscaped = true)
    {
        $text = $this->getField($type, true);
        if (!$text)
            return '';

        $text = Util::parseHtmlText($text);

        if ($jsEscaped)
            $text = Util::jsEscape($text);

        return $text;
    }

    public function renderTooltip()
    {
        if (!$this->curTpl)
            return null;

        $title = Lang::unescapeUISequences(Util::htmlEscape($this->getField('name', true)), Lang::FMT_HTML);
        $level = $this->curTpl['level'];
        if ($level < 0)
            $level = 0;

        $x = '';
        if ($level)
        {
            $level = sprintf(Lang::quest('questLevel'), $level);

            if ($this->curTpl['flags'] & QUEST_FLAG_DAILY)  // daily
                $level .= ' '.Lang::quest('daily');

            $x .= '<table><tr><td><table width="100%"><tr><td><b class="q">'.$title.'</b></td><th><b class="q0">'.$level.'</b></th></tr></table></td></tr></table>';
        }
        else
            $x .= '<table><tr><td><b class="q">'.$title.'</b></td></tr></table>';


        $x .= '<table><tr><td><br />'.$this->parseText('objectives', false);


        $xReq = '';
        for ($i = 1; $i < 5; $i++)
        {
            $ot     = $this->getField('objectiveText'.$i, true);
            $rng    = $this->curTpl['reqNpcOrGo'.$i];
            $rngQty = $this->curTpl['reqNpcOrGoCount'.$i];

            if ($rngQty < 1 && (!$rng || $ot))
                continue;

            if ($ot)
                $name = $ot;
            else
                $name = $rng > 0 ? CreatureList::getName($rng) : Lang::unescapeUISequences(GameObjectList::getName(-$rng), Lang::FMT_HTML);

            $xReq .= '<br /> - '.$name.($rngQty > 1 ? ' x '.$rngQty : null);
        }

        for ($i = 1; $i < 7; $i++)
        {
            $ri    = $this->curTpl['reqItemId'.$i];
            $riQty = $this->curTpl['reqItemCount'.$i];

            if (!$ri || $riQty < 1)
                continue;

            $xReq .= '<br /> - '.Lang::unescapeUISequences(ItemList::getName($ri), Lang::FMT_HTML).($riQty > 1 ? ' x '.$riQty : null);
        }

        if ($et = $this->getField('end', true))
            $xReq .= '<br /> - '.$et;

        if ($_ = $this->getField('rewardOrReqMoney'))
            if ($_ < 0)
                $xReq .= '<br /> - '.Lang::quest('money').Lang::main('colon').Util::formatMoney(abs($_));

        if ($xReq)
            $x .= '<br /><br /><span class="q">'.Lang::quest('requirements').Lang::main('colon').'</span>'.$xReq;

        $x .= '</td></tr></table>';

        return $x;
    }

    public function getJSGlobals($addMask = GLOBALINFO_ANY)
    {
        $data = [];

        foreach ($this->iterate() as $__)
        {
            if ($addMask & GLOBALINFO_REWARDS)
            {
                // items
                for ($i = 1; $i < 5; $i++)
                    if ($this->curTpl['rewardItemId'.$i] > 0)
                        $data[Type::ITEM][$this->curTpl['rewardItemId'.$i]] = $this->curTpl['rewardItemId'.$i];

                for ($i = 1; $i < 7; $i++)
                    if ($this->curTpl['rewardChoiceItemId'.$i] > 0)
                        $data[Type::ITEM][$this->curTpl['rewardChoiceItemId'.$i]] = $this->curTpl['rewardChoiceItemId'.$i];

                // spells
                if ($this->curTpl['rewardSpell'] > 0)
                    $data[Type::SPELL][$this->curTpl['rewardSpell']] = $this->curTpl['rewardSpell'];

                if ($this->curTpl['rewardSpellCast'] > 0)
                    $data[Type::SPELL][$this->curTpl['rewardSpellCast']] = $this->curTpl['rewardSpellCast'];

                // titles
                if ($this->curTpl['rewardTitleId'] > 0)
                    $data[Type::TITLE][$this->curTpl['rewardTitleId']] = $this->curTpl['rewardTitleId'];

                // currencies
                if (!empty($this->rewards[$this->id][Type::CURRENCY]))
                    foreach ($this->rewards[$this->id][Type::CURRENCY] as $id => $__)
                        $data[Type::CURRENCY][$id] = $id;
            }

            if ($addMask & GLOBALINFO_SELF)
                $data[Type::QUEST][$this->id] = ['name' => $this->getField('name', true)];
        }

        return $data;
    }
}


class QuestListFilter extends Filter
{
    public    $extraOpts     = [];
    protected $enums         = array(
        37 => parent::ENUM_CLASSS,                          // classspecific
        38 => parent::ENUM_RACE,                            // racespecific
         9 => parent::ENUM_FACTION,                         // objectiveearnrepwith
        33 => parent::ENUM_EVENT,                           // relatedevent
        43 => parent::ENUM_CURRENCY,                        // currencyrewarded
         1 => parent::ENUM_FACTION,                         // increasesrepwith
        10 => parent::ENUM_FACTION                          // decreasesrepwith
    );

    protected $genericFilter = array(
         1 => [parent::CR_CALLBACK,  'cbReputation',     '>',                  null], // increasesrepwith
         2 => [parent::CR_NUMERIC,   'rewardXP',         NUM_CAST_INT              ], // experiencegained
         3 => [parent::CR_NUMERIC,   'rewardOrReqMoney', NUM_CAST_INT              ], // moneyrewarded
         4 => [parent::CR_CALLBACK,  'cbSpellRewards',   null,                 null], // spellrewarded [yn]
         5 => [parent::CR_FLAG,      'flags',            QUEST_FLAG_SHARABLE       ], // sharable
         6 => [parent::CR_NUMERIC,   'timeLimit',        NUM_CAST_INT              ], // timer
         7 => [parent::CR_NYI_PH,    null,               1                         ], // firstquestseries
         9 => [parent::CR_CALLBACK,  'cbEarnReputation', null,                 null], // objectiveearnrepwith [enum]
        10 => [parent::CR_CALLBACK,  'cbReputation',     '<',                  null], // decreasesrepwith
        11 => [parent::CR_NUMERIC,   'suggestedPlayers', NUM_CAST_INT              ], // suggestedplayers
        15 => [parent::CR_NYI_PH,    null,               1                         ], // lastquestseries
        16 => [parent::CR_NYI_PH,    null,               1                         ], // partseries
        18 => [parent::CR_FLAG,      'cuFlags',          CUSTOM_HAS_SCREENSHOT     ], // hasscreenshots
        19 => [parent::CR_CALLBACK,  'cbQuestRelation',  0x1,                  null], // startsfrom [enum]
        21 => [parent::CR_CALLBACK,  'cbQuestRelation',  0x2,                  null], // endsat [enum]
        22 => [parent::CR_CALLBACK,  'cbItemRewards',    null,                 null], // itemrewards [op] [int]
        23 => [parent::CR_CALLBACK,  'cbItemChoices',    null,                 null], // itemchoices [op] [int]
        24 => [parent::CR_CALLBACK,  'cbLacksStartEnd',  null,                 null], // lacksstartend [yn]
        25 => [parent::CR_FLAG,      'cuFlags',          CUSTOM_HAS_COMMENT        ], // hascomments
        27 => [parent::CR_FLAG,      'flags',            QUEST_FLAG_DAILY          ], // daily
        28 => [parent::CR_FLAG,      'flags',            QUEST_FLAG_WEEKLY         ], // weekly
        29 => [parent::CR_CALLBACK,  'cbRepeatable',     null                      ], // repeatable
        30 => [parent::CR_NUMERIC,   'id',               NUM_CAST_INT,         true], // id
        33 => [parent::CR_ENUM,      'e.holidayId',      true,                 true], // relatedevent
        34 => [parent::CR_CALLBACK,  'cbAvailable',      null,                 null], // availabletoplayers [yn]
        36 => [parent::CR_FLAG,      'cuFlags',          CUSTOM_HAS_VIDEO          ], // hasvideos
        37 => [parent::CR_CALLBACK,  'cbClassSpec',      null,                 null], // classspecific [enum]
        38 => [parent::CR_CALLBACK,  'cbRaceSpec',       null,                 null], // racespecific [enum]
        42 => [parent::CR_STAFFFLAG, 'flags'                                       ], // flags
        43 => [parent::CR_CALLBACK,  'cbCurrencyReward', null,                 null], // currencyrewarded [enum]
        44 => [parent::CR_CALLBACK,  'cbLoremaster',     null,                 null], // countsforloremaster_stc [yn]
        45 => [parent::CR_BOOLEAN,   'rewardTitleId'                               ]  // titlerewarded
    );

    protected $inputFields = array(
        'cr'    => [parent::V_RANGE, [1, 45],                                                             true ], // criteria ids
        'crs'   => [parent::V_LIST,  [parent::ENUM_NONE, parent::ENUM_ANY, [0, 99999]],                   true ], // criteria operators
        'crv'   => [parent::V_REGEX, parent::PATTERN_INT,                                                 true ], // criteria values - only numerals
        'na'    => [parent::V_REGEX, parent::PATTERN_NAME,                                                false], // name / text - only printable chars, no delimiter
        'ex'    => [parent::V_EQUAL, 'on',                                                                false], // also match subname
        'ma'    => [parent::V_EQUAL, 1,                                                                   false], // match any / all filter
        'minle' => [parent::V_RANGE, [1, 99],                                                             false], // min quest level
        'maxle' => [parent::V_RANGE, [1, 99],                                                             false], // max quest level
        'minrl' => [parent::V_RANGE, [1, 99],                                                             false], // min required level
        'maxrl' => [parent::V_RANGE, [1, 99],                                                             false], // max required level
        'si'    => [parent::V_LIST,  [-SIDE_HORDE, -SIDE_ALLIANCE, SIDE_ALLIANCE, SIDE_HORDE, SIDE_BOTH], false], // side
        'ty'    => [parent::V_LIST,  [0, 1, 21, 41, 62, [81, 85], 88, 89],                                true ]  // type
    );

    protected function createSQLForValues()
    {
        $parts = [];
        $_v    = $this->fiData['v'];

        // name
        if (isset($_v['na']))
        {
            $_ = [];
            if (isset($_v['ex']) && $_v['ex'] == 'on')
                $_ = $this->modularizeString(['name_loc'.Lang::getLocale()->value, 'objectives_loc'.Lang::getLocale()->value, 'details_loc'.Lang::getLocale()->value]);
            else
                $_ = $this->modularizeString(['name_loc'.Lang::getLocale()->value]);

            if ($_)
                $parts[] = $_;
        }

        // level min
        if (isset($_v['minle']))
            $parts[] = ['level', $_v['minle'], '>='];       // not considering quests that are always at player level (-1)

        // level max
        if (isset($_v['maxle']))
            $parts[] = ['level', $_v['maxle'], '<='];

        // reqLevel min
        if (isset($_v['minrl']))
            $parts[] = ['minLevel', $_v['minrl'], '>='];    // ignoring maxLevel

        // reqLevel max
        if (isset($_v['maxrl']))
            $parts[] = ['minLevel', $_v['maxrl'], '<='];    // ignoring maxLevel

        // side
        if (isset($_v['si']))
        {
            $ex    = [['reqRaceMask', ChrRace::MASK_ALL, '&'], ChrRace::MASK_ALL, '!'];
            $notEx = ['OR', ['reqRaceMask', 0], [['reqRaceMask', ChrRace::MASK_ALL, '&'], ChrRace::MASK_ALL]];

            switch ($_v['si'])
            {
                case  SIDE_BOTH:
                    $parts[] = $notEx;
                    break;
                case  SIDE_HORDE:
                    $parts[] = ['OR', $notEx, ['reqRaceMask', ChrRace::MASK_HORDE, '&']];
                    break;
                case -SIDE_HORDE:
                    $parts[] = ['AND', $ex,   ['reqRaceMask', ChrRace::MASK_HORDE, '&']];
                    break;
                case  SIDE_ALLIANCE:
                    $parts[] = ['OR', $notEx, ['reqRaceMask', ChrRace::MASK_ALLIANCE, '&']];
                    break;
                case -SIDE_ALLIANCE:
                    $parts[] = ['AND', $ex,   ['reqRaceMask', ChrRace::MASK_ALLIANCE, '&']];
                    break;
            }
        }

        // type [list]
        if (isset($_v['ty']))
            $parts[] = ['type', $_v['ty']];

        return $parts;
    }

    protected function cbReputation($cr, $sign)
    {
        if (!Util::checkNumeric($cr[1], NUM_CAST_INT))
            return false;

        if (!in_array($cr[1], $this->enums[$cr[0]]))
            return false;

        if ($_ = DB::Aowow()->selectRow('SELECT * FROM ?_factions WHERE `id` = ?d', $cr[1]))
            $this->formData['reputationCols'][] = [$cr[1], Util::localizedString($_, 'name')];

        return [
            'OR',
            ['AND', ['rewardFactionId1', $cr[1]], ['rewardFactionValue1', 0, $sign]],
            ['AND', ['rewardFactionId2', $cr[1]], ['rewardFactionValue2', 0, $sign]],
            ['AND', ['rewardFactionId3', $cr[1]], ['rewardFactionValue3', 0, $sign]],
            ['AND', ['rewardFactionId4', $cr[1]], ['rewardFactionValue4', 0, $sign]],
            ['AND', ['rewardFactionId5', $cr[1]], ['rewardFactionValue5', 0, $sign]]
        ];
    }

    protected function cbQuestRelation($cr, $flags)
    {
        return match ($cr[1])
        {
            Type::NPC,
            Type::OBJECT,
            Type::ITEM   => ['AND', ['qse.type', $cr[1]], ['qse.method', $flags, '&']],
            default      => false
        };
    }

    protected function cbCurrencyReward($cr)
    {
        if (!Util::checkNumeric($cr[1], NUM_CAST_INT))
            return false;

        if (!in_array($cr[1], $this->enums[$cr[0]]))
            return false;

        return [
            'OR',
            ['rewardItemId1', $cr[1]], ['rewardItemId2', $cr[1]], ['rewardItemId3', $cr[1]], ['rewardItemId4', $cr[1]],
            ['rewardChoiceItemId1', $cr[1]], ['rewardChoiceItemId2', $cr[1]], ['rewardChoiceItemId3', $cr[1]], ['rewardChoiceItemId4', $cr[1]], ['rewardChoiceItemId5', $cr[1]], ['rewardChoiceItemId6', $cr[1]]
        ];
    }

    protected function cbAvailable($cr)
    {
        if (!$this->int2Bool($cr[1]))
            return false;

        if ($cr[1])
            return [['cuFlags', CUSTOM_UNAVAILABLE | CUSTOM_DISABLED, '&'], 0];
        else
            return ['cuFlags', CUSTOM_UNAVAILABLE | CUSTOM_DISABLED, '&'];
    }

    protected function cbRepeatable($cr)
    {
        if (!$this->int2Bool($cr[1]))
            return false;

        if ($cr[1])
            return ['OR', ['flags', QUEST_FLAG_REPEATABLE, '&'], ['specialFlags', QUEST_FLAG_SPECIAL_REPEATABLE, '&']];
        else
            return ['AND', [['flags', QUEST_FLAG_REPEATABLE, '&'], 0], [['specialFlags', QUEST_FLAG_SPECIAL_REPEATABLE, '&'], 0]];
    }

    protected function cbItemChoices($cr)
    {
        if (!Util::checkNumeric($cr[2], NUM_CAST_INT) || !$this->int2Op($cr[1]))
            return false;

        $this->extraOpts['q']['s'][] = ', (IF(rewardChoiceItemId1, 1, 0) + IF(rewardChoiceItemId2, 1, 0) + IF(rewardChoiceItemId3, 1, 0) + IF(rewardChoiceItemId4, 1, 0) + IF(rewardChoiceItemId5, 1, 0) + IF(rewardChoiceItemId6, 1, 0)) as numChoices';
        $this->extraOpts['q']['h'][] = 'numChoices '.$cr[1].' '.$cr[2];
        return [1];
    }

    protected function cbItemRewards($cr)
    {
        if (!Util::checkNumeric($cr[2], NUM_CAST_INT) || !$this->int2Op($cr[1]))
            return false;

        $this->extraOpts['q']['s'][] = ', (IF(rewardItemId1, 1, 0) + IF(rewardItemId2, 1, 0) + IF(rewardItemId3, 1, 0) + IF(rewardItemId4, 1, 0)) as numRewards';
        $this->extraOpts['q']['h'][] = 'numRewards '.$cr[1].' '.$cr[2];
        return [1];
    }

    protected function cbLoremaster($cr)
    {
        if (!$this->int2Bool($cr[1]))
            return false;

        if ($cr[1])
            return ['AND', ['zoneOrSort', 0, '>'], [['flags', QUEST_FLAG_DAILY | QUEST_FLAG_WEEKLY | QUEST_FLAG_REPEATABLE, '&'], 0], [['specialFlags', QUEST_FLAG_SPECIAL_REPEATABLE | QUEST_FLAG_SPECIAL_MONTHLY, '&'], 0]];
        else
            return ['OR', ['zoneOrSort', 0, '<'], ['flags', QUEST_FLAG_DAILY | QUEST_FLAG_WEEKLY | QUEST_FLAG_REPEATABLE, '&'], ['specialFlags', QUEST_FLAG_SPECIAL_REPEATABLE | QUEST_FLAG_SPECIAL_MONTHLY, '&']];
    }

    protected function cbSpellRewards($cr)
    {
        if (!$this->int2Bool($cr[1]))
            return false;

        if ($cr[1])
            return ['OR', ['sourceSpellId', 0, '>'], ['rewardSpell', 0, '>'], ['rsc.effect1Id', SpellList::EFFECTS_TEACH], ['rsc.effect2Id', SpellList::EFFECTS_TEACH], ['rsc.effect3Id', SpellList::EFFECTS_TEACH]];
        else
            return ['AND', ['sourceSpellId', 0], ['rewardSpell', 0], ['rewardSpellCast', 0]];
    }

    protected function cbEarnReputation($cr)
    {
        if (!Util::checkNumeric($cr[1], NUM_CAST_INT))
            return false;

        if ($cr[1] == parent::ENUM_ANY)              // any
            return ['OR', ['reqFactionId1', 0, '>'], ['reqFactionId2', 0, '>']];
        else if ($cr[1] == parent::ENUM_NONE)        // none
            return ['AND', ['reqFactionId1', 0], ['reqFactionId2', 0]];
        else if (in_array($cr[1], $this->enums[$cr[0]]))
            return ['OR', ['reqFactionId1', $cr[1]], ['reqFactionId2', $cr[1]]];

        return false;
    }

    protected function cbClassSpec($cr)
    {
        if (!isset($this->enums[$cr[0]][$cr[1]]))
            return false;

        $_ = $this->enums[$cr[0]][$cr[1]];
        if ($_ === true)
            return ['AND', ['reqClassMask', 0, '!'], [['reqClassMask', ChrClass::MASK_ALL, '&'], ChrClass::MASK_ALL, '!']];
        else if ($_ === false)
            return ['OR', ['reqClassMask', 0], [['reqClassMask', ChrClass::MASK_ALL, '&'], ChrClass::MASK_ALL]];
        else if (is_int($_))
            return ['AND', ['reqClassMask', ChrClass::from($_)->toMask(), '&'], [['reqClassMask', ChrClass::MASK_ALL, '&'], ChrClass::MASK_ALL, '!']];

        return false;
    }

    protected function cbRaceSpec($cr)
    {
        if (!isset($this->enums[$cr[0]][$cr[1]]))
            return false;

        $_ = $this->enums[$cr[0]][$cr[1]];
        if ($_ === true)
            return ['AND', ['reqRaceMask', 0, '!'], [['reqRaceMask', ChrRace::MASK_ALL, '&'], ChrRace::MASK_ALL, '!'], [['reqRaceMask', ChrRace::MASK_ALLIANCE, '&'], ChrRace::MASK_ALLIANCE, '!'], [['reqRaceMask', ChrRace::MASK_HORDE, '&'], ChrRace::MASK_HORDE, '!']];
        else if ($_ === false)
            return ['OR', ['reqRaceMask', 0], ['reqRaceMask', ChrRace::MASK_ALL], ['reqRaceMask', ChrRace::MASK_ALLIANCE], ['reqRaceMask', ChrRace::MASK_HORDE]];
        else if (is_int($_))
            return ['AND', ['reqRaceMask', ChrRace::from($_)->toMask(), '&'], [['reqRaceMask', ChrRace::MASK_ALLIANCE, '&'], ChrRace::MASK_ALLIANCE, '!'], [['reqRaceMask', ChrRace::MASK_HORDE, '&'], ChrRace::MASK_HORDE, '!']];

        return false;
    }

    protected function cbLacksStartEnd($cr)
    {
        if (!$this->int2Bool($cr[1]))
            return false;

        $missing = DB::Aowow()->selectCol('SELECT `questId`, BIT_OR(`method`) AS "se" FROM ?_quests_startend GROUP BY `questId` HAVING "se" <> 3');
        if ($cr[1])
            return ['id', $missing];
        else
            return ['id', $missing, '!'];
    }
}


?>
