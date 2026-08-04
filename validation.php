<?php 
include_once(__DIR__ . '/test_sqlsrv.php');
include('menuaccess.php');

//mysql_connect('localhost','qtechbpo_idol','p@$$w0rd') or die("Error");
//mysql_select_db("qtechbpo_barangay"); 
session_start();


if (isset($_GET['menu']))
{
    $_SESSION['menu']=$_GET['menu'];
    if ($_SESSION['menu']=="Dashboard")
    {
        if ($_SESSION['UserCategory']=="DSP")
        {header('location: dashboard_dsp.php');}
        else
        {header('location: dashboardpage.php');}
    }
    elseif ($_SESSION['menu']=="Transaction"){
        if ($_SESSION['UserCategory']=="DSP")
        {header('location:transaction.php ');}
        else
       { header('location: transaction.php');}
    }
    elseif ($_SESSION['menu']=="Data"){
        if ($_SESSION['UserCategory']=="DSP")
        {header('location: dataentry.php');}
        else
       {
        header('location: dataentry.php');}
    }
    elseif ($_SESSION['menu']=="Records"){
        if ($_SESSION['UserCategory']=="DSP")
        {header('location: records.php');}
        else
       {
        header('location: records.php');}
    }
    elseif ($_SESSION['menu']=="Settings"){
        if ($_SESSION['UserCategory']=="DSP")
        {header('location: ');}
        else
       {
        header('location: settings.php');}
    }
    	
   
}


// variable declaration
$username = "";
$email    = "";
$errors   = array(); 

if (isset($_POST['login_btn1'])) {
    login();
}
if (isset($_POST['Forgotsubmit'])) {
    Forgotsubmit();
}

// call the register() function if register_btn is clicked
if (isset($_POST['SignUp_btn'])) {
    SigUp();
}
if (isset($_POST['user_access_btn'])) {
    UserAccess();
}
if (isset($_GET['logout'])) {
    session_destroy();
    unset($_SESSION['user']);
    header("location: index.php");
}
//global $conn, $username, $pass;
function UserAccess(){
    global $conn, $errors, $query1 ;
    $ID    =  ($_POST['ID']);
    $UserType   =  ($_POST['user_type']);

   
    $query1 = "UPDATE users SET user_type='$UserType'  WHERE id = '".  $ID ."'" ;
         
    $stmt= sqlsrv_query($conn, $query1);
    if( $stmt === false )
    {
     die( print_r( sqlsrv_errors(), true));
       }
       else{

       
        ?> <script type='text/javascript'>
alert('Record has been Update');
</script><?php

        header('location:pending.php');
    }
}

function Forgotsubmit()
{
    global $conn, $errors,$Email ;
 $email    =  ($_POST['email']);
    if (empty($email)) { 
        array_push($errors, "Email is required"); 
        $error = "Email is required.";
        }

           if (count($errors) == 0) 
        {
              $sql1 = " SELECT * FROM TBL_HREmployeeList WHERE Email_Address = '$email'  ";
            $params1 = array();
            $options1 = array( "Scrollable" => SQLSRV_CURSOR_KEYSET );
            $stmt1 = sqlsrv_query( $conn , $sql1 , $params1 , $options1 );
            if( sqlsrv_num_rows($stmt1) == 1) 
            {
                    date_default_timezone_set('Asia/Manila');
 $row1 = sqlsrv_fetch_array($stmt1);
$fullname=$row1['FirstName'].' '.$row1['LastName'];
$employeeID=$row1['EmployeeID'];
$fromEmail='hr.tradewell@gmail.com';

$datetoken = date('m-d-Y H:i:s', strtotime("+30 minutes"));
$token = bin2hex(random_bytes(32));
$link="http://122.52.195.3/tradewellportal/password_update.php?token=".$token;
    $queryT = "UPDATE users SET token_key='$token',token_expired_date='$datetoken'  WHERE EmployeeID = '". $employeeID ."'" ;
         
    $stmtT= sqlsrv_query($conn, $queryT);
      $subject = "Tradewell Password Reset -" . $fullname;
 $htmlMessage = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Your Password</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table width="500" cellpadding="20" cellspacing="0" style="background-color: #ffffff; border-radius: 8px;">
                    <tr>
                        <td align="center">
                              <img class="img-fluid"  src="http://122.52.195.3/tradewellportal/image/TL.png">
                            <h2 style="color: #333;">Password Reset Request</h2>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <p>Hello,</p><strong>{$fullname}</strong>
                            <p>We received a request to reset your password. Click the button below to create a new password.</p>
                            
                            <p style="text-align: center;">
                                <a href="{$link}"
                                   style="background-color: #007bff; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 5px;">
                                   Reset Password
                                </a>
                            </p>

                            <p>If you did not request a password reset, please ignore this email.</p>

                            <p>Thank you,<br>Urban Tradewell Corporation</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

</body>
</html>

HTML;
 $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";

     
    

        @mail($email, $subject, $htmlMessage, $headers);

        header('location:forgot_password_reset_sent.php');
        exit();



            }
             else
            {

                $error = "The Email Input is Not Match in Our Record.";


            }
    }
    else
        {

        }
        }

function SigUp(){
    global $conn, $errors, $success,$error, $query, $gender, $sbuject, $text ;
    $error = "";
    $success="";
    // receive all input values from the form
    date_default_timezone_set('Asia/Manila');
    $employeeID    =  ($_POST['EID']);
    $username    =  ($_POST['username']);
    $password_1  =  ($_POST['password_1']);
    $password_2  =  ($_POST['password_2']);
    $lastname    =  ($_POST['lastName']);
    $firstname   =  ($_POST['firstName']);
    $email      =   ($_POST['email']);
    $dattime = date('m-d-Y H:i:s');
    $subject="Registration";
   

    // form validation: ensure that the form is correctly filled
    if (empty($employeeID)) { 
        array_push($errors, "Employee is required"); 
        $error = "EmployeeID is required.";
    }
    if (empty($lastname)) { 
        array_push($errors, "LastName is required"); 
        $error = "LastName is required.";
    }
    if (empty($firstname)) { 
        array_push($errors, "FirstName is required"); 
        $error = "FirstName is required.";
    }
    if (empty($username)) { 
        array_push($errors, "Username is required"); 
        $error = "Username is required.";
    }
    if (empty($email)) { 
        array_push($errors, "Email is required"); 
        $error = "Email is required.";
    }
    if (empty($password_1)) { 
        array_push($errors, "Password is required"); 
        $error = "Password is required.";
    }
    if ($password_1 != $password_2) {
        array_push($errors, "The two passwords do not match");
        $error = "The two passwords do not match";
    }

    // register user if there are no errors in the form
    if (count($errors) == 0) 
        {
            $sql1 = " SELECT * FROM TBL_HREmployeeList WHERE EmployeeID = '$employeeID' AND LastName ='$lastname' AND FirstName ='$firstname' ";
            $params1 = array();
            $options1 = array( "Scrollable" => SQLSRV_CURSOR_KEYSET );
            $stmt1 = sqlsrv_query( $conn , $sql1 , $params1 , $options1 );
            if( sqlsrv_num_rows($stmt1) == 1) 
            {
                $sql2 = " SELECT * FROM users WHERE EmployeeID = '$employeeID' ";
                $params2 = array();
                $options2= array( "Scrollable" => SQLSRV_CURSOR_KEYSET );
                $stmt2= sqlsrv_query( $conn , $sql2 , $params2 , $options2 );
                if( sqlsrv_num_rows($stmt2) >0) 
                    {
                        $error = "EmployeeID is Already Registered.";

                    }
                else
                    {
                        $sqlu = " SELECT * FROM users WHERE username = '$username' ";
                        $paramsu = array();
                        $optionsu= array( "Scrollable" => SQLSRV_CURSOR_KEYSET );
                        $stmtu= sqlsrv_query( $conn , $sqlu , $paramsu , $optionsu);
                        if( sqlsrv_num_rows($stmtu) >0) 
                            {
                            
                              
                                $error = "UserName is Already Taken.";
                            }
                        else
                            {
                                $row1 = sqlsrv_fetch_array($stmt1);
                                $password = md5($password_1);//encrypt the password before saving in the database
                                $query = "INSERT INTO users (username, EmployeeID, user_type, password, Reg_Datetime ) 
                                        VALUES('$username', '$employeeID', 'pending' ,'$password','$dattime')";
                                $stmt=sqlsrv_query($conn, $query);
                                if( $stmt === false )
                                    {
                                    die( print_r( sqlsrv_errors(), true));
                                    }
                                    else
                                    {
                               
                                                      date_default_timezone_set('Asia/Manila');
                                                    
                                                     $text="Hello!  ".$row1['FirstName']." ".$row1['LastName'];
                                                     $text.="\r\n Department : ".$row1['Department'];
                                                      $text.="\r\n Position : ".$row1['Position_held'];
                                                     $text.="\r\n Job Tittle : ".$row1['Job_tittle'];
                                                        $text.="\r\n Date/Time  : ".date('m-d-Y')."/".date('H:i:s')."\r\n";
                                                      $text.="\r\n To Confirm your Registration Click the Link Below: \r\n";
                                                       $text.="Link: http://122.52.195.3/tradewellportal/Login.php \r\n";
                                                   
                        $success='Successfully registered, please contact administrator for account activition';
                       
                                                   if (mail($email, $subject, $text))
                                                        {
                                                           $_SESSION['Email']=$email;
                                                            header('location: Register_Thank.php');	
                                                       } 
                                                    else 
                                                        {
                                                      
                                                       $error = "Email sending failed...";
                                                        }            
                                            
                                     }
                            }
                    }
        }
            
            else
            {

                $error = "The EmployeeID, LastName And FirstName Not Match in Our Record.";


            }
            
        }

}

function isLoggedIn()
	{
		if (isset($_SESSION['user'])) {
			return true;
		}else{
			return false;
		}
    }
    function display_error()
    {
		global $errors;

		if (count($errors) > 0)
        {
			echo '<div class="error">';
				foreach ($errors as $error)
                {
					echo $error .'<br>';
				}
			echo '</div>';
		}
	}
    function login()
    {
        global $conn, $username, $params,$options,$stmt;
    
    $username=($_POST['username']);
    $pass=($_POST['password']);
    $pass = md5($pass);
    
    if (empty($username))
     { 
        array_push($errors, "Username is required"); 
    }
    
    if (empty($pass)) 
    { 
        array_push($errors, "Password is required"); 
    }
    
        $sql = " SELECT * FROM ViewUserRegister WHERE username = '$username' AND password ='$pass' ";
        $params = array();
        $options = array( "Scrollable" => SQLSRV_CURSOR_KEYSET );
        $stmt = sqlsrv_query( $conn , $sql , $params , $options );
    
            if( sqlsrv_num_rows($stmt) >0) 
                {
                $logged_in_user = sqlsrv_fetch_array($stmt);
                            
                //$logged_in_user = SQLSRV_FETCH_ARRAY($stmt);
                
                //if (($logged_in_user['user_type']) == 'admin') {
    
                //$_SESSION['user'] = $logged_in_user;
                // $_SESSION['success']  = "You are now logged in";
                $_SESSION['user'] = htmlspecialchars($logged_in_user['username']);
                $_SESSION['firstname'] = htmlspecialchars($logged_in_user['FirstName']);
                $_SESSION['lastname'] = htmlspecialchars($logged_in_user['LastName']);
                $_SESSION['userlevel'] = $logged_in_user['user_type'];
                $_SESSION['EmployeeID'] = $logged_in_user['EmployeeID'];
                $_SESSION['Position'] = $logged_in_user['Position_held'];
		$_SESSION['UserID'] = $logged_in_user['id'];
		$_SESSION['User_Type'] = $logged_in_user['user_type'];
                $_SESSION['DepartmentName'] = $logged_in_user['Department'];
		$_SESSION['JobTitle'] = $logged_in_user['Job_tittle'];
           $_SESSION['UserCategory'] = $logged_in_user['Category'];
           
           
                if($logged_in_user['user_type'] =='Admin')
                {
                    if($logged_in_user['Category'] =='DSP')
                    {
                        $_SESSION['menu']="Dashboard";
                        header('location: dashboardpage.php');	
                        MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                    }
                    else{
                        $_SESSION['menu']="Dashboard";
                        header('location: dashboardpage.php');	
                        MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                    }
                  
                
                } elseif($logged_in_user['user_type'] =='Logistic')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                }		
elseif($logged_in_user['user_type'] =='HR')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                }  
		elseif($logged_in_user['user_type'] =='Accounting1')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
		elseif($logged_in_user['user_type'] =='Maintenance')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='Expenses')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
		elseif($logged_in_user['user_type'] =='IT')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboardpage.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='Salesman')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboard_dsp.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='DSIP')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboard_dsp.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type']=='user'){
    
                    header('location:dashboardpage.php');
                } 
                elseif($logged_in_user['user_type'] =='Diser')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboard_dp.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='Leadman')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboard_dp.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='Delivery')
                {
                    $_SESSION['menu']="Dashboard";
                    header('location: dashboard_dp.php');	
                    MenuAccess($logged_in_user['user_type'],$logged_in_user['Category']);
                } 
                elseif($logged_in_user['user_type'] =='pending')
                {
                    header('location: index.php');
                } 
                else{
                    
                    header('location: index.php');
                }
    
                }
            else 
                {
                  
                    if(file_exists("mytestfile.txt")) {
                        $file = fopen("mytestfile.txt", "r");
                      } else {
                        die("Error: The Username and Password does not exist.");
                      }
                  
                  
                }
    }
?>