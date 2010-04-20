<?php
/*
 * LimeSurvey
 * Copyright (C) 2007 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 *
 * $Id$
 */

//Ensure script is not run directly, avoid path disclosure 
include_once("login_check.php");

// A FILE TO IMPORT A DUMPED SURVEY FILE, AND CREATE A NEW SURVEY

$importgroup = "<div class='header'>".$clang->gT("Import question group")."</div>\n";
$importgroup .= "<div class='messagebox'>\n";

$sFullFilepath = $tempdir . "/" . $_FILES['the_file']['name'];
$aPathInfo = pathinfo($sFullFilepath);
$sExtension = $aPathInfo['extension'];

if (!@move_uploaded_file($_FILES['the_file']['tmp_name'], $sFullFilepath))
{
    $fatalerror = sprintf ($clang->gT("An error occurred uploading your file. This may be caused by incorrect permissions in your %s folder."),$tempdir);
}

// validate that we have a SID
if (!returnglobal('sid'))
{
    $fatalerror .= $clang->gT("No SID (Survey) has been provided. Cannot import question.");
}
else
{
    $surveyid=returnglobal('sid');
}

if (isset($fatalerror))
{
    $importquestion .= "<div class='warningheader'>".$clang->gT("Error")."</div><br />\n";
    $importquestion .= $fatalerror."<br /><br />\n";
    $importquestion .= "<input type='submit' value='".$clang->gT("Main Admin Screen")."' onclick=\"window.open('$scriptname', '_top')\" /><br /><br />\n";
    $importquestion .= "</div>\n";
    unlink($sFullFilepath);
    return;
}

// IF WE GOT THIS FAR, THEN THE FILE HAS BEEN UPLOADED SUCCESFULLY
$importgroup .= "<div class='successheader'>".$clang->gT("Success")."</div>&nbsp;<br />\n"
.$clang->gT("File upload succeeded.")."<br /><br />\n"
.$clang->gT("Reading file...")."<br />\n";


if (strtolower($sExtension)=='csv')
{
    $importresults=CSVImportGroup($sFullFilepath, $surveyid);
}
elseif (strtolower($sExtension)=='lsg')
{
    $importresults=XMLImportGroup($sFullFilepath, $surveyid);
}
else die('Unknown file extension');


if (isset($skippedlanguages))
{
    $importgroup.="<br /><div class='partialheader'>".$clang->gT("Import partially successful.")."</div><br />";
    $importgroup.=$clang->gT("The following languages in this group were not imported since the survey does not contain such a language: ")."<br />";
    foreach  ($skippedlanguages as $sl)
    {
        $importgroup.= getLanguageNameFromCode($grouprowdata['language'], false).'<br />';
    }
    $importgroup.='<br />';
}
else
{
    $importgroup .= "<br />\n<div class='successheader'>".$clang->gT("Success")."</div><br />\n";
}
$importgroup .="<strong><u>".$clang->gT("Group Import Summary")."</u></strong><br />\n"
."<ul>\n\t<li>".$clang->gT("Groups:");
if (isset($countgroups)) {$importgroup .= $countgroups;}
$importgroup .= "</li>\n"
."\t<li>".$clang->gT("Questions").": ";
if (isset($countquestions)) {$importgroup .= $countquestions;}
$importgroup .= "</li>\n"
."\t<li>".$clang->gT("Answers").": ";
if (isset($countanswers)) {$importgroup .= $countanswers;}
$importgroup .= "</li>\n"
."\t<li>".$clang->gT("Conditions").": ";
if (isset($countconditions)) {$importgroup .= $countconditions;}
$importgroup .= "</li>\n"
."\t<li>".$clang->gT("Label Set").": ";
if (isset($countlabelsets)) {$importgroup .= $countlabelsets;}
$importgroup .= " (".$clang->gT("Labels").": ";
if (isset($countlabels)) {$importgroup .= $countlabels;}
$importgroup .= ")</li>\n";
$importgroup .= "\t<li>".$clang->gT("Question Attributes: ");
$importgroup .= $countquestion_attributes;
$importgroup .= "</li>\n</ul>\n";
$importgroup .= "<strong>".$clang->gT("Import of group is completed.")."</strong><br /><br />\n";
$importgroup .= "<input type='submit' value='".$clang->gT("Go to group")."' onclick=\"window.open('$scriptname?sid=$newsid&amp;gid=$newgid', '_top')\" />\n";


unlink($sFullFilepath);


function CSVImportGroup($sFullFilepath, $newsid)    
{
    global $dbprefix, $connect;       
    $handle = fopen($sFullFilepath, "r");
    while (!feof($handle))
    {
        $buffer = fgets($handle);
        $bigarray[] = $buffer;
    }
    fclose($handle);

    if (substr($bigarray[0], 0, 23) != "# LimeSurvey Group Dump" && substr($bigarray[0], 0, 24) != "# PHPSurveyor Group Dump")
    {
        $importgroup .= "<br /><div class='warningheader'>".$clang->gT("Error")."</div><br />\n";
        $importgroup .= $clang->gT("This file is not a LimeSurvey group file. Import failed.")."<br /><br />\n";
        $importgroup .= "<input type='submit' value='".$clang->gT("Main Admin Screen")."' onclick=\"window.open('$scriptname', '_top')\" />\n";
        $importgroup .= "</div>\n";
        unlink($sFullFilepath);
        return;
    }
    else
    {
        $importversion=(int)trim(substr($bigarray[1],12));
    }

    for ($i=0; $i<9; $i++)
    {
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //GROUPS
    if (array_search("# QUESTIONS TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# QUESTIONS TABLE\n", $bigarray);
    }
    elseif (array_search("# QUESTIONS TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# QUESTIONS TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray)-1;
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$grouparray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //QUESTIONS
    if (array_search("# ANSWERS TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# ANSWERS TABLE\n", $bigarray);
    }
    elseif (array_search("# ANSWERS TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# ANSWERS TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray)-1;
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$questionarray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //ANSWERS
    if (array_search("# CONDITIONS TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# CONDITIONS TABLE\n", $bigarray);
    }
    elseif (array_search("# CONDITIONS TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# CONDITIONS TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray)-1;
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$answerarray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //CONDITIONS
    if (array_search("# LABELSETS TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# LABELSETS TABLE\n", $bigarray);
    }
    elseif (array_search("# LABELSETS TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# LABELSETS TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray);
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$conditionsarray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //LABELSETS
    if (array_search("# LABELS TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# LABELS TABLE\n", $bigarray);
    }
    elseif (array_search("# LABELS TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# LABELS TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray)-1;
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$labelsetsarray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //LABELS
    if (array_search("# QUESTION_ATTRIBUTES TABLE\n", $bigarray))
    {
        $stoppoint = array_search("# QUESTION_ATTRIBUTES TABLE\n", $bigarray);
    }
    elseif (array_search("# QUESTION_ATTRIBUTES TABLE\r\n", $bigarray))
    {
        $stoppoint = array_search("# QUESTION_ATTRIBUTES TABLE\r\n", $bigarray);
    }
    else
    {
        $stoppoint = count($bigarray)-1;
    }
    for ($i=0; $i<=$stoppoint+1; $i++)
    {
        if ($i<$stoppoint-2) {$labelsarray[] = $bigarray[$i];}
        unset($bigarray[$i]);
    }
    $bigarray = array_values($bigarray);

    //LAST LOT (now question_attributes)
    if (!isset($noconditions) || $noconditions != "Y")
    {
        // stoppoint is the last line number
        // this is an empty line after the QA CSV lines
        $stoppoint = count($bigarray)-1;
        for ($i=0; $i<=$stoppoint+1; $i++)
        {
            if ($i<=$stoppoint-1) {$question_attributesarray[] = $bigarray[$i];}
            unset($bigarray[$i]);
        }
    }
    $bigarray = array_values($bigarray);

    $countgroups=0;
    if (isset($questionarray))
    {
        $questionfieldnames=convertCSVRowToArray($questionarray[0],',','"');
        unset($questionarray[0]);
        $countquestions = 0;
    }

    if (isset($answerarray))
    {
        $answerfieldnames=convertCSVRowToArray($answerarray[0],',','"');
        unset($answerarray[0]);
        $countanswers = 0;
    }

    $countconditions = 0;
    $countlabelsets=0;
    $countlabels=0;
    $countquestion_attributes = 0;
    $countanswers = 0;


    // first check that imported group, questions and labels support the
    // current survey's baselang
    $langcode = GetBaseLanguageFromSurveyID($newsid);
    if (isset($grouparray))
    {
        $groupfieldnames = convertCSVRowToArray($grouparray[0],',','"');
        $langfieldnum = array_search("language", $groupfieldnames);
        $gidfieldnum = array_search("gid", $groupfieldnames);
        $groupssupportbaselang = bDoesImportarraySupportsLanguage($grouparray,Array($gidfieldnum),$langfieldnum,$langcode,true);
        if (!$groupssupportbaselang)
        {
            $importgroup .= "<br /><div class='warningheader'>".$clang->gT("Error")."</div><br />\n";
            $importgroup .= $clang->gT("You can't import a group which doesn't support the current survey's base language.")."<br /><br />\n";
            $importgroup .= "<input type='submit' value='".$clang->gT("Main Admin Screen")."' onclick=\"window.open('$scriptname', '_top')\" />\n";
            $importgroup .= "</div>\n";
            unlink($sFullFilepath);
            return;
        }
    }

    if (isset($questionarray))
    {
        $langfieldnum = array_search("language", $questionfieldnames);
        $qidfieldnum = array_search("qid", $questionfieldnames);
        $questionssupportbaselang = bDoesImportarraySupportsLanguage($questionarray,Array($qidfieldnum), $langfieldnum,$langcode,false);
        if (!$questionssupportbaselang)
        {
            $importgroup .= "<br /><div class='warningheader'>".$clang->gT("Error")."</div><br />\n";
            $importgroup .= $clang->gT("You can't import a question which doesn't support the current survey's base language.")."<br /><br />\n";
            $importgroup .= "<input type='submit' value='".$clang->gT("Main Admin Screen")."' onclick=\"window.open('$scriptname', '_top')\" />\n";
            $importgroup .= "</div>\n";
            unlink($sFullFilepath);
            return;
        }
    }


    if (isset($labelsetsarray))
    {
        $labelsetfieldname = convertCSVRowToArray($labelsetsarray[0],',','"');
        $langfieldnum = array_search("languages", $labelsetfieldname);
        $lidfilednum =  array_search("lid", $labelsetfieldname);
        $labelsetssupportbaselang = bDoesImportarraySupportsLanguage($labelsetsarray,Array($lidfilednum),$langfieldnum,$langcode,true);
        if (!$labelsetssupportbaselang)
        {
            $importquestion .= "<br /><div class='warningheader'>".$clang->gT("Error")."</div><br />\n"
            .$clang->gT("You can't import label sets which don't support the current survey's base language")."<br /><br />\n"
            ."</div>\n";
            $importgroup .= "<input type='submit' value='".$clang->gT("Main Admin Screen")."' onclick=\"window.open('$scriptname', '_top')\" />\n";
            unlink($sFullFilepath);
            return;
        }
    }

    $newlids = array(); // this array will have the "new lid" for the label sets, the key will be the "old lid"

    //DO ANY LABELSETS FIRST, SO WE CAN KNOW WHAT THEIR NEW LID IS FOR THE QUESTIONS
    if (isset($labelsetsarray) && $labelsetsarray) {
        $csarray=buildLabelSetCheckSumArray();   // build checksums over all existing labelsets
        $count=0;
        foreach ($labelsetsarray as $lsa) {
            $fieldorders  =convertCSVRowToArray($labelsetsarray[0],',','"');
            $fieldcontents=convertCSVRowToArray($lsa,',','"');
            if ($count==0) {$count++; continue;}

            $countlabelsets++;

            $labelsetrowdata=array_combine($fieldorders,$fieldcontents);

            // Save old labelid
            $oldlid=$labelsetrowdata['lid'];
            // set the new language
            unset($labelsetrowdata['lid']);
            $newvalues=array_values($labelsetrowdata);
            $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
            $lsainsert = "INSERT INTO {$dbprefix}labelsets (".implode(',',array_keys($labelsetrowdata)).") VALUES (".implode(',',$newvalues).")"; //handle db prefix
            $lsiresult=$connect->Execute($lsainsert);

            // Get the new insert id for the labels inside this labelset
            $newlid=$connect->Insert_ID("{$dbprefix}labelsets",'lid');

            if ($labelsarray) {
                $count=0;
                foreach ($labelsarray as $la) {
                    $lfieldorders  =convertCSVRowToArray($labelsarray[0],',','"');
                    $lfieldcontents=convertCSVRowToArray($la,',','"');
                    if ($count==0) {$count++; continue;}

                    // Combine into one array with keys and values since its easier to handle
                    $labelrowdata=array_combine($lfieldorders,$lfieldcontents);
                    $labellid=$labelrowdata['lid'];
                    if ($importversion<=132)
                    {
                        $labelrowdata["assessment_value"]=(int)$labelrowdata["code"];
                    }
                    if ($labellid == $oldlid) {
                        $labelrowdata['lid']=$newlid;

                        // translate internal links
                        $labelrowdata['title']=translink('label', $oldlid, $newlid, $labelrowdata['title']);

                        $newvalues=array_values($labelrowdata);
                        $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
                        $lainsert = "INSERT INTO {$dbprefix}labels (".implode(',',array_keys($labelrowdata)).") VALUES (".implode(',',$newvalues).")"; //handle db prefix
                        $liresult=$connect->Execute($lainsert);
                        $countlabels++;
                    }
                }
            }

            //CHECK FOR DUPLICATE LABELSETS
            $thisset="";
            $query2 = "SELECT code, title, sortorder, language, assessment_value
                       FROM {$dbprefix}labels
                       WHERE lid=".$newlid."
                       ORDER BY language, sortorder, code";    
            $result2 = db_execute_num($query2) or safe_die("Died querying labelset $lid<br />$query2<br />".$connect->ErrorMsg());
            while($row2=$result2->FetchRow())
            {
                $thisset .= implode('.', $row2);
            } // while
            $newcs=dechex(crc32($thisset)*1);
            unset($lsmatch);
            if (isset($csarray))
            {
                foreach($csarray as $key=>$val)
                {
                    if ($val == $newcs)
                    {
                        $lsmatch=$key;
                    }
                }
            }
            if (isset($lsmatch))
            {
                //There is a matching labelset. So, we will delete this one and refer
                //to the matched one.
                $query = "DELETE FROM {$dbprefix}labels WHERE lid=$newlid";
                $result=$connect->Execute($query) or safe_die("Couldn't delete labels<br />$query<br />".$connect->ErrorMsg());
                $query = "DELETE FROM {$dbprefix}labelsets WHERE lid=$newlid";
                $result=$connect->Execute($query) or safe_die("Couldn't delete labelset<br />$query<br />".$connect->ErrorMsg());
                $newlid=$lsmatch;
            }
            else
            {
                //There isn't a matching labelset, add this checksum to the $csarray array
                $csarray[$newlid]=$newcs;
            }
            //END CHECK FOR DUPLICATES
            $labelreplacements[]=array($oldlid, $newlid);
            $newlids[$oldlid] = $newlid;
        }
    }

    //these arrays will aloud to insert correctly groups an questions multi languague survey imports correctly, and will eliminate the need to "searh" the imported data
    //$newgids = array(); // this array will have the "new gid" for the groups, the kwy will be the "old gid"    <-- not needed when importing groups
    $newqids = array(); // this array will have the "new qid" for the questions, the kwy will be the "old qid"

    // DO GROUPS, QUESTIONS FOR GROUPS, THEN ANSWERS FOR QUESTIONS IN A __NOT__ NESTED FORMAT!
    if (isset($grouparray) && $grouparray)
    {
        $surveylanguages=GetAdditionalLanguagesFromSurveyID($surveyid);
        $surveylanguages[]=GetBaseLanguageFromSurveyID($surveyid);

        // do GROUPS
        $gafieldorders=convertCSVRowToArray($grouparray[0],',','"');
        unset($grouparray[0]);
        $newgid = 0;
        $group_order = 0;   // just to initialize this variable
        foreach ($grouparray as $ga)
        {
            //GET ORDER OF FIELDS
            $gacfieldcontents=convertCSVRowToArray($ga,',','"');
            $grouprowdata=array_combine($gafieldorders,$gacfieldcontents);

            // Skip not supported languages
            if (!in_array($grouprowdata['language'],$surveylanguages))
            {
                $skippedlanguages[]=$grouprowdata['language'];  // this is for the message in the end.
                continue;
            }

            // replace the sid
            $oldsid=$grouprowdata['sid'];
            $grouprowdata['sid']=$newsid;

            // replace the gid  or remove it if needed (it also will calculate the group order if is a new group)
            $oldgid=$grouprowdata['gid'];
            if ($newgid == 0)
            {
                unset($grouprowdata['gid']);

                // find the maximum group order and use this grouporder+1 to assign it to the new group
                $qmaxgo = "select max(group_order) as maxgo from ".db_table_name('groups')." where sid=$newsid";
                $gres = db_execute_assoc($qmaxgo) or safe_die ($clang->gT("Error")." Failed to find out maximum group order value<br />\n$qmaxqo<br />\n".$connect->ErrorMsg());
                $grow=$gres->FetchRow();
                $group_order = $grow['maxgo']+1;
            }
            else
            $grouprowdata['gid'] = $newgid;

            $grouprowdata["group_order"]= $group_order;

            // Everything set - now insert it
            $grouprowdata=array_map('convertCsvreturn2return', $grouprowdata);


            // translate internal links
            $grouprowdata['group_name']=translink('survey', $oldsid, $newsid, $grouprowdata['group_name']);
            $grouprowdata['description']=translink('survey', $oldsid, $newsid, $grouprowdata['description']);

            $newvalues=array_values($grouprowdata);
            $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
            if (isset($grouprowdata['gid'])) {@$connect->Execute('SET IDENTITY_INSERT '.db_table_name('groups')." ON");}

            $ginsert = "insert INTO {$dbprefix}groups (".implode(',',array_keys($grouprowdata)).") VALUES (".implode(',',$newvalues).")";
            $gres = $connect->Execute($ginsert) or safe_die($clang->gT("Error").": Failed to insert group<br />\n$ginsert<br />\n".$connect->ErrorMsg());
            if (isset($grouprowdata['gid'])) {@$connect->Execute('SET IDENTITY_INSERT '.db_table_name('groups').' OFF');}

            //GET NEW GID  .... if is not done before and we count a group if a new gid is required
            if ($newgid == 0)
            {
                $newgid = $connect->Insert_ID("{$dbprefix}groups",'gid');
                $countgroups++;
            }
        }
        // GROUPS is DONE

        // do QUESTIONS
        if (isset($questionarray) && $questionarray)
        {
            foreach ($questionarray as $qa)
            {
                $qacfieldcontents=convertCSVRowToArray($qa,',','"');
                $questionrowdata=array_combine($questionfieldnames,$qacfieldcontents);

                // Skip not supported languages
                if (!in_array($questionrowdata['language'],$surveylanguages))
                continue;

                // replace the sid
                $questionrowdata["sid"] = $newsid;

                // replace the gid (if the gid is not in the oldgid it means there is a problem with the exported record, so skip it)
                if ($questionrowdata['gid'] == $oldgid)
                $questionrowdata['gid'] = $newgid;
                else
                continue; // a problem with this question record -> don't consider

                // replace the qid or remove it if needed
                $oldqid = $questionrowdata['qid'];
                if (isset($newqids[$oldqid]))
                $questionrowdata['qid'] = $newqids[$oldqid];
                else
                unset($questionrowdata['qid']);

                // replace the lid for the new one (if there is no new lid in the $newlids array it mean that was not imported -> error, skip this record)
                if (in_array($questionrowdata["type"], array("F","H","W","Z", "1", ":", ";")))      // only fot the questions that uses a label set.
                if (isset($newlids[$questionrowdata["lid"]]))
                {
                    $questionrowdata["lid"] = $newlids[$questionrowdata["lid"]];
                    if(isset($newlids[$questionrowdata["lid1"]]))
                    {
                        $questionrowdata["lid1"] = $newlids[$questionrowdata["lid1"]];
                    }
                }
                else
                {
                    continue; // a problem with this question record -> don't consider
                }
                //            $other = $questionrowdata["other"]; //Get 'other' field value
                //            $oldlid = $questionrowdata['lid'];

                // Everything set - now insert it
                $questionrowdata=array_map('convertCsvreturn2return', $questionrowdata);

                // translate internal links
                $questionrowdata['title']=translink('survey', $oldsid, $newsid, $questionrowdata['title']);
                $questionrowdata['question']=translink('survey', $oldsid, $newsid, $questionrowdata['question']);
                $questionrowdata['help']=translink('survey', $oldsid, $newsid, $questionrowdata['help']);

                $newvalues=array_values($questionrowdata);
                $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
                $qinsert = "insert INTO {$dbprefix}questions (".implode(',',array_keys($questionrowdata)).") VALUES (".implode(',',$newvalues).")";
                if (isset($questionrowdata['qid'])) {@$connect->Execute('SET IDENTITY_INSERT '.db_table_name('questions').' ON');}
                $qres = $connect->Execute($qinsert) or safe_die ($clang->gT("Error")."Failed to insert question<br />\n$qinsert<br />\n".$connect->ErrorMsg());
                if (isset($questionrowdata['qid'])) {@$connect->Execute('SET IDENTITY_INSERT '.db_table_name('questions').' OFF');}

                //GET NEW QID  .... if is not done before and we count a question if a new qid is required
                if (!isset($newqids[$oldqid]))
                {
                    $newqids[$oldqid] = $connect->Insert_ID("{$dbprefix}questions",'qid');
                    $countquestions++;
                }
            }
        }
        // QESTIONS is DONE

        // do ANSWERS
        if (isset($answerarray) && $answerarray)
        {
            foreach ($answerarray as $aa)
            {
                $aacfieldcontents=convertCSVRowToArray($aa,',','"');
                $answerrowdata=array_combine($answerfieldnames,$aacfieldcontents);

                // Skip not supported languages
                if (!in_array($answerrowdata['language'],$surveylanguages))
                continue;

                // replace the qid for the new one (if there is no new qid in the $newqids array it mean that this answer is orphan -> error, skip this record)
                if (isset($newqids[$answerrowdata["qid"]]))
                $answerrowdata["qid"] = $newqids[$answerrowdata["qid"]];
                else
                continue; // a problem with this answer record -> don't consider

                if ($importversion<=132)
                {
                    $answerrowdata["assessment_value"]=(int)$answerrowdata["code"];
                }


                // Everything set - now insert it
                $answerrowdata = array_map('convertCsvreturn2return', $answerrowdata);

                // translate internal links
                $answerrowdata['answer']=translink('survey', $oldsid, $newsid, $answerrowdata['answer']);

                $newvalues=array_values($answerrowdata);
                $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
                $ainsert = "insert INTO {$dbprefix}answers (".implode(',',array_keys($answerrowdata)).") VALUES (".implode(',',$newvalues).")";
                $ares = $connect->Execute($ainsert) or safe_die ($clang->gT("Error")."Failed to insert answer<br />\n$ainsert<br />\n".$connect->ErrorMsg());
                $countanswers++;
            }
        }
        // ANSWERS is DONE

        // Fix Group sortorder
        fixsortorderGroups();
        //... and for the questions inside the groups
        // get all group ids and fix questions inside each group
        $gquery = "SELECT gid FROM {$dbprefix}groups where sid=$newsid group by gid ORDER BY gid"; //Get last question added (finds new qid)
        $gres = db_execute_assoc($gquery);
        while ($grow = $gres->FetchRow())
        {
            fixsortorderQuestions($grow['gid'], $surveyid);
        }
    }

    // do ATTRIBUTES
    if (isset($question_attributesarray) && $question_attributesarray)
    {
        $fieldorders  =convertCSVRowToArray($question_attributesarray[0],',','"');
        unset($question_attributesarray[0]);
        foreach ($question_attributesarray as $qar) {
            $fieldcontents=convertCSVRowToArray($qar,',','"');
            $qarowdata=array_combine($fieldorders,$fieldcontents);

            // replace the qid for the new one (if there is no new qid in the $newqids array it mean that this attribute is orphan -> error, skip this record)
            if (isset($newqids[$qarowdata["qid"]]))
            $qarowdata["qid"] = $newqids[$qarowdata["qid"]];
            else
            continue; // a problem with this answer record -> don't consider

            unset($qarowdata["qaid"]);

            // Everything set - now insert it
            $newvalues=array_values($qarowdata);
            $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
            $qainsert = "insert INTO {$dbprefix}question_attributes (".implode(',',array_keys($qarowdata)).") VALUES (".implode(',',$newvalues).")";
            $result=$connect->Execute($qainsert) or safe_die ("Couldn't insert question_attribute<br />$qainsert<br />".$connect->ErrorMsg());
            $countquestion_attributes++;
        }
    }
    // ATTRIBUTES is DONE

    // do CONDITIONS
    if (isset($conditionsarray) && $conditionsarray)
    {
        $fieldorders=convertCSVRowToArray($conditionsarray[0],',','"');
        unset($conditionsarray[0]);
        foreach ($conditionsarray as $car) {
            $fieldcontents=convertCSVRowToArray($car,',','"');
            $conditionrowdata=array_combine($fieldorders,$fieldcontents);

            $oldqid = $conditionrowdata["qid"];
            $oldcqid = $conditionrowdata["cqid"];

            // replace the qid for the new one (if there is no new qid in the $newqids array it mean that this condition is orphan -> error, skip this record)
            if (isset($newqids[$oldqid]))
            $conditionrowdata["qid"] = $newqids[$oldqid];
            else
            continue; // a problem with this answer record -> don't consider

            // replace the cqid for the new one (if there is no new qid in the $newqids array it mean that this condition is orphan -> error, skip this record)
            if (isset($newqids[$oldcqid]))
            $conditionrowdata["cqid"] = $newqids[$oldcqid];
            else
            continue; // a problem with this answer record -> don't consider

            list($oldcsid, $oldcgid, $oldqidanscode) = explode("X",$conditionrowdata["cfieldname"],3);

            if ($oldcgid != $oldgid)    // this means that the condition is in another group (so it should not have to be been exported -> skip it
            continue;

            unset($conditionrowdata["cid"]);

            // recreate the cfieldname with the new IDs
            if (preg_match("/^\+/",$oldcsid))
            {
                $newcfieldname = '+'.$newsid . "X" . $newgid . "X" . $conditionrowdata["cqid"] .substr($oldqidanscode,strlen($oldqid));
            }
            else
            {
                $newcfieldname = $newsid . "X" . $newgid . "X" . $conditionrowdata["cqid"] .substr($oldqidanscode,strlen($oldqid));
            }

            $conditionrowdata["cfieldname"] = $newcfieldname;
            if (!isset($conditionrowdata["method"]) || trim($conditionrowdata["method"])=='')
            {
                $conditionrowdata["method"]='==';
            }
            $newvalues=array_values($conditionrowdata);
            $newvalues=array_map(array(&$connect, "qstr"),$newvalues); // quote everything accordingly
            $conditioninsert = "insert INTO {$dbprefix}conditions (".implode(',',array_keys($conditionrowdata)).") VALUES (".implode(',',$newvalues).")";
            $result=$connect->Execute($conditioninsert) or safe_die ("Couldn't insert condition<br />$conditioninsert<br />".$connect->ErrorMsg());
            $countconditions++;
        }
    }
    // CONDITIONS is DONE
    
}


/**
* This function imports a LimeSurvey .lsg question group XML file
* 
* @param mixed $sFullFilepath  The full filepath of the uploaded file
* @param mixed $newsid The new survey id - the group will always be added after the last group in the survey   
*/
function XMLImportGroup($sFullFilepath, $newsid)
{
    global $connect, $dbprefix, $clang;
    $aLanguagesSupported = array();  // this array will keep all the languages supported for the survey

    $sBaseLanguage = GetBaseLanguageFromSurveyID($newsid);
    $aLanguagesSupported[]=$sBaseLanguage;     // adds the base language to the list of supported languages
    $aLanguagesSupported=array_merge($aLanguagesSupported,GetAdditionalLanguagesFromSurveyID($newsid));
    
    $xml = simplexml_load_file($sFullFilepath);    
    if ($xml->LimeSurveyDocType!='Group') safe_die('This is not a valid LimeSurvey group structure XML file.');
    $dbversion = (int) $xml->DBVersion;
    $gidmappings=array();     
    $qidmappings=array();     
    $results['defaultvalues']=0;
    $results['answers']=0;
    $results['question_attributes']=0;
    $results['subquestions']=0;
    
    $importlanguages=array();
    foreach ($xml->languages->language as $language)
    {
        $importlanguages[]=(string)$language;
    }     

    if (!in_array($sBaseLanguage,$importlanguages))
    {
        $results['fatalerror'] = $clang->gT("The languages of the imported group file must at least include the base language of this survey.");
        return;
    }
    // First get an overview of fieldnames - it's not useful for the moment but might be with newer versions
    /*
    $fieldnames=array();
    foreach ($xml->questions->fields->fieldname as $fieldname )
    {
        $fieldnames[]=(string)$fieldname;
    };*/
    
                           
    // Import group table ===================================================================================

    $tablename=$dbprefix.'groups';
    $newgrouporder=$connect->GetOne("SELECT MAX(group_order) AS maxqo FROM ".db_table_name('group')." WHERE sid=$newsid")+1;
    if ($newgrouporder===false) 
    {
        $newgrouporder=0;
    }
    else {
        $newgrouporder++;
    }
    foreach ($xml->groups->rows->row as $row)
    {
       $insertdata=array(); 
        foreach ($row as $key=>$value)
        {
            $insertdata[(string)$key]=(string)$value;
        }
        $oldsid=$insertdata['sid'];
        $insertdata['sid']=$newsid;
        $insertdata['group_order']=$newgrouporder;
        $oldgid=$insertdata['gid']; unset($insertdata['gid']); // save the old qid

        // now translate any links
        $insertdata['group_name']=translink('survey', $oldsid, $newsid, $insertdata['group_name']);
        $insertdata['description']=translink('survey', $oldsid, $newsid, $insertdata['description']);
        // Insert the new question    
        if (isset($gidmappings[$oldgid]))
        {
           $insertdata['gid']=$gidmappings[$oldgid]; 
        }   
        $query=$connect->GetInsertSQL($tablename,$insertdata); 
        $result = $connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />{$query}<br />\n".$connect->ErrorMsg());
        if (!isset($gidmappings[$oldgid]))
        {
            $newgid=$connect->Insert_ID($tablename,"gid"); // save this for later
            $gidmappings[$oldgid]=$newgid; // add old and new qid to the mapping array
        }
    }
                           
                                                                                      
    // Import questions table ===================================================================================

    // We have to run the question table data two times - first to find all main questions
    // then for subquestions (because we need to determine the new qids for the main questions first)
    $tablename=$dbprefix.'questions';
    $newquestionorder=$connect->GetOne("SELECT MAX(question_order) AS maxqo FROM ".db_table_name('questions')." WHERE sid=$newsid AND gid=$newgid")+1;
    if ($newquestionorder===false) 
    {
        $newquestionorder=0;
    }
    else {
        $newquestionorder++;
    }
    foreach ($xml->questions->rows->row as $row)
    {
       $insertdata=array(); 
        foreach ($row as $key=>$value)
        {
            $insertdata[(string)$key]=(string)$value;
        }
        $oldsid=$insertdata['sid'];
        $insertdata['sid']=$newsid;
        $insertdata['gid']=$gidmappings[$insertdata['gid']];
        $insertdata['question_order']=$newquestionorder;
        $oldqid=$insertdata['qid']; unset($insertdata['qid']); // save the old qid

        // now translate any links
        $insertdata['title']=translink('survey', $oldsid, $newsid, $insertdata['title']);
        $insertdata['question']=translink('survey', $oldsid, $newsid, $insertdata['question']);
        $insertdata['help']=translink('survey', $oldsid, $newsid, $insertdata['help']);
        // Insert the new question    
        if (isset($qidmappings[$oldqid]))
        {
           $insertdata['qid']=$qidmappings[$oldqid]; 
        }   
        $query=$connect->GetInsertSQL($tablename,$insertdata); 
        $result = $connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />{$query}<br />\n".$connect->ErrorMsg());
        if (!isset($qidmappings[$oldqid]))
        {
            $newqid=$connect->Insert_ID($tablename,"qid"); // save this for later
            $qidmappings[$oldqid]=$newqid; // add old and new qid to the mapping array
        }
    }

    // Import subquestions --------------------------------------------------------------
    foreach ($xml->subquestions->rows->row as $row)
    {
        $insertdata=array(); 
        foreach ($row as $key=>$value)
        {
            $insertdata[$key]=$value;
        }
        $insertdata['sid']=$newsid;
        $insertdata['gid']=$gidmappings[(int)$insertdata['gid']];;
        $oldsqid=(int)$insertdata['qid']; unset($insertdata['qid']); // save the old qid
        $insertdata['parent_qid']=$qidmappings[(int)$insertdata['parent_qid']]; // remap the parent_qid

        // now translate any links
        $insertdata['title']=translink('survey', $oldsid, $newsid, $insertdata['title']);
        $insertdata['question']=translink('survey', $oldsid, $newsid, $insertdata['question']);
        $insertdata['help']=translink('survey', $oldsid, $newsid, $insertdata['help']);
        if (isset($qidmappings[$oldsqid])){
           $insertdata['qid']=$qidmappings[$oldsqid];
        }
        
        $query=$connect->GetInsertSQL($tablename,$insertdata); 
        $result = $connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />{$query}<br />\n".$connect->ErrorMsg());
        $newsqid=$connect->Insert_ID($tablename,"qid"); // save this for later
        if (!isset($insertdata['qid']))
        {
            $qidmappings[$oldsqid]=$newsqid; // add old and new qid to the mapping array                
        }
        $results['subquestions']++;
    }

    // Import answers --------------------------------------------------------------
    if(isset($xml->answers))
    {
        $tablename=$dbprefix.'answers';
        
        foreach ($xml->answers->rows->row as $row)
        {
           $insertdata=array(); 
            foreach ($row as $key=>$value)
            {
                $insertdata[$key]=$value;
            }
            $insertdata['qid']=$qidmappings[(int)$insertdata['qid']]; // remap the parent_qid

            // now translate any links
            $query=$connect->GetInsertSQL($tablename,$insertdata); 
            $result=$connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />{$query}<br />\n".$connect->ErrorMsg());
            $results['answers']++;
        }            
    }

    // Import questionattributes --------------------------------------------------------------
    if(isset($xml->question_attributes))
    {
        $tablename=$dbprefix.'question_attributes';
        
        foreach ($xml->question_attributes->rows->row as $row)
        {
            $insertdata=array(); 
            foreach ($row as $key=>$value)
            {
                $insertdata[$key]=$value;
            }
            unset($insertdata['qaid']);
            $insertdata['qid']=$qidmappings[(integer)$insertdata['qid']]; // remap the parent_qid

            // now translate any links
            $query=$connect->GetInsertSQL($tablename,$insertdata); 
            $result=$connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />{$query}<br />\n".$connect->ErrorMsg());
            $results['question_attributes']++;
        }        
    }
    
    
    // Import defaultvalues --------------------------------------------------------------
    if(isset($xml->defaultvalues))
    {
        $tablename=$dbprefix.'defaultvalues';
        
        $results['defaultvalues']=0;
        foreach ($xml->defaultvalues->rows->row as $row)
        {
           $insertdata=array(); 
            foreach ($row as $key=>$value)
            {
                $insertdata[$key]=$value;
            }
            $insertdata['qid']=$qidmappings[(int)$insertdata['qid']]; // remap the qid
            $insertdata['sqid']=$qidmappings[(int)$insertdata['sqid']]; // remap the subqeustion id

            // now translate any links
            $query=$connect->GetInsertSQL($tablename,$insertdata); 
            $result=$connect->Execute($query) or safe_die ($clang->gT("Error").": Failed to insert data<br />\$query<br />\n".$connect->ErrorMsg());
            $results['defaultvalues']++;
        }             
    }
    
    $results['newqid']=$newqid;
    $results['questions']=1;
    $results['labelsets']=0;
    $results['labels']=0;
    return $results;
}