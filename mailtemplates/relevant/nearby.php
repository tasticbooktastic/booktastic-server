<?php
namespace Booktastic\Iznik;

require_once(IZNIK_BASE . '/mailtemplates/header.php');
require_once(IZNIK_BASE . '/mailtemplates/footer.php');

function relevant_nearby($domain, $logo, $name, $distance, $subject, $msgid, $type, $email, $noemail) {
    $siteurl = "https://$domain";
    $sitename = SITE_NAME;
    $posturl = "$siteurl/message/$msgid?src=nearby";
    $appeal = $type == Message::TYPE_OFFER ? "would want it" :"can help them out";

    $html = <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
    <title>$subject</title>
EOT;

    $html .= mail_header();
    $html .= <<<EOT
<!-- Start Background -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F7F5EB">
    <tr>
        <td width="100%" valign="top" align="center">

            <!-- Start Wrapper  -->
            <table width="95%" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#FFFFFF">
                <tr>
                    <td height="10" style="font-size:10px; line-height:10px;">   </td><!-- Spacer -->
                </tr>
                <tr>
                    <td align="center">

                        <!-- Start Container  -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" class="container">
                            <tr>
                                <td width="100%" class="mobile" style="font-family:arial; font-size:12px; line-height:18px;">
                                    <table width="95%" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#FFFFFF">
                                        <tr>
                                            <td height="20" style="font-size:10px; line-height:10px;"> </td><!-- Spacer -->
                                        </tr>
                                        <tr>
                                            <td align="center">
                                                <table width="95%" cellpadding="0" cellspacing="0" border="0" class="container">
                                                    <tbody>
                                                        <tr>
                                                            <td width="150" class="mobileOff">
                                                                <table class="button" width="90%" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                    <tr>
                                                                        <td>                                                           
                                                                            <a href="$siteurl">
                                                                                <img src="$logo" style="width: 100px; height: 100px; border-radius:3px; margin:0; padding:0; border:none; display:block;" alt="" class="imgClass" />
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                </table>               
                                                            </td>    
                                                            <td>
                                                                <p>.</p>
                                                                <table width="100%">
                                                                    <tr>
                                                                        <td>
                                                                            <p>$name, who's just $distance miles from you, has posted <a href="$posturl">$subject</a>.</p>
                                                                            <p>Do you know anyone who $appeal?  Please ask your friends!</p> 
                                                                        </td>
                                                                    </tr>                                                                    
                                                                    <tr>
                                                                        <td>
                                                                            <table width="170" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                                <tr>
                                                                                    <td width="170" height="36" bgcolor="darkgreen" align="center" valign="middle"
                                                                                        style="font-family: Century Gothic, Arial, sans-serif; font-size: 16px; color: #ffffff;
                                                                                            line-height:18px; border-radius:3px;">
                                                                                        <a href="$posturl" alias="" style="font-family: Century Gothic, Arial, sans-serif; text-decoration: none; color: white;">View their post</a>
                                                                                    </td>
                                                                                </tr>
                                                                            </table>
                                                                        </td>
                                                                    </tr>                                                                    
                                                                    <tr>
                                                                        <td>
                                                                            <p>If that's not clickable, copy and paste this: $posturl</p>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>        
                                                        <tr>
                                                            <td height="20" style="font-size:10px; line-height:10px;"> </td><!-- Spacer -->
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2">
                                                                <font color=gray><hr></font>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="color: grey; font-size:10px;">
                                                                <p>You've received this automated mail because you're a member of <a href="$siteurl">$sitename</a>.  This mail was sent to $email.  These suggestions are automated, so they won't always be right.  <span style="color: black"><b>If you don't find them useful, you can change your settings by clicking <a href="$siteurl/settings">here</a>, or turn these suggestion mails off by emailing <a href="mailto:$noemail">$noemail</a>.</b></font></p>
                                                                <p>Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.  Registered address: Weaver's Field, Loud Bridge, Chipping PR3 2NX</p> 
                                                            </td>
                                                        </tr>        
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td height=\"10\" style=\"font-size:10px; line-height:10px;\"> </td>
                </tr>
           </table>
       </td>
       </tr>
</table>

</body>
</html>
EOT;

    return($html);
}