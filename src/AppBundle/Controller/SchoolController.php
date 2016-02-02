<?php
/*this is the controller for the school page
*it controls all links starting with school/
*/

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\ChoiceList;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Form\Type\LearnerPersonalType;
use AppBundle\Entity\Lwd;
use AppBundle\Form\Type\RoomStateType;
use AppBundle\Entity\RoomState;
use AppBundle\Form\Type\TeacherType;
use AppBundle\Entity\Snt;
use AppBundle\Form\Type\LearnerDisabilityType;
use AppBundle\Form\Type\LearnerPerformanceType;
use AppBundle\Entity\Performance;
use AppBundle\Form\Type\NeedsType;
use AppBundle\Entity\Guardian;
use AppBundle\Entity\SchoolHasSnt;
use AppBundle\Entity\Need;
use AppBundle\Entity\ResourceRoom;
use AppBundle\Form\Type\ResourceRoomType;
use AppBundle\Entity\LwdHasDisability;
use AppBundle\Form\Type\LearnerExitType;

use AppBundle\Form\Type\LwdFinderType;
use AppBundle\Entity\SchoolExit;
Use AppBundle\Form\Type\TransferType;

class SchoolController extends Controller{
	/**
	 *@Route("/school/{emisCode}", name="school_main", requirements={"emisCode":"\d+"})
	 */
	public function schoolMainAction($emisCode, Request $request){

            $connection = $this->get('database_connection');
            $schools =  $connection->fetchAll('SELECT * FROM school NATURAL JOIN zone
                    NATURAL JOIN district WHERE emiscode = ?',array($emisCode));

            $sumquery = 'SELECT count(iddisability) FROM lwd 
            NATURAL JOIN lwd_has_disability NATURAL JOIN disability NATURAL JOIN lwd_belongs_to_school
            WHERE emiscode = ?';
            $disabilities = $connection->fetchAll("SELECT disability_name, count(iddisability) as num_learners,($sumquery) as total 
                    FROM lwd NATURAL JOIN lwd_has_disability NATURAL JOIN disability NATURAL JOIN lwd_belongs_to_school
                    WHERE emiscode = ? AND year = ? GROUP BY iddisability", array($emisCode,$emisCode,date('Y')));
            $session = $request->getSession();
            //keep the emiscode of the selected school in the session so we can always redirect to it until the next school is chosen
            $session->set('emiscode', $emisCode);
            //keep the name of the selected school in the session to access it from the school selection form
            $session->set('school_name', $schools[0]['school_name']);
            
            //keep all information about the selected school in the session    
            $session->set('schoolInfo', $schools[0]);
            
            return $this->render('school/school2.html.twig',
                    array('school' => $schools[0],
                            'disabilities' => $disabilities)
                    );
	}
        /**
	 *@Route("/school/{emisCode}/materials/{link}", name="school_materials", requirements = {"emisCode":"\d+", "link":"fresh|resource|room"}, options={"expose"= true})
	 */
        public function materialsAction($emisCode, $link, Request $request){

            if($link == 'resource'){
                return $this->render('school/materials/resources_main.html.twig');
            }else {
                return $this->render('school/materials/materials_main.html.twig');
            }
        }
         /**
	 *@Route("/findResourceForm/{emisCode}", name="find_need_materials")
	 */
        public function findResourceFormAction($emisCode, Request $request){//this controller will return the form used for selecting a learner
	$connection = $this->get('database_connection');
		$needs = $connection->fetchAll('SELECT * FROM need n NATURAL JOIN school_has_need s WHERE s.emiscode = ?', array($emisCode));
                   
		$choices = array();
		foreach ($needs as $key => $row) {
			$choices[$row['idneed']] = $row['idneed'].': '.$row['needname'];
		}

		//create the form for choosing an existing student to edit
        	$defaultData = array();
        	$form = $this->createFormBuilder($defaultData, array(
        		'action' => $this->generateUrl('find_need_materials', ['emisCode'=>$emisCode])))
        	->add('need','choice', array(
        		'label' => 'Choose Resource',
        		'placeholder'=>'Edit Resource',
        		'choices'=> $choices,
        		))
        	->getForm();

        	$form->handleRequest($request);

        	if($form->isValid()){
        		$formData = $form->getData();
        		$needId = $formData['need'];
        		return $this->redirectToRoute('edit_resource_material',array('emisCode'=>$emisCode,'needId'=>$needId));
        	}
        	return $this->render('school/materials/findresourceform.html.twig', array(
        		'form' => $form->createView()));
        }
        /**
	 *@Route("/findMaterialForm/{emisCode}", name="find_school_materials")
	 */
        public function findMaterialFormAction($emisCode, Request $request){//this controller will return the form used for selecting a learner
	$connection = $this->get('database_connection');
		$materials = $connection->fetchAll('SELECT room_id, year FROM room_state WHERE emiscode = ?', array($emisCode));
                   //room_id, year, enough_light, enough_space, adaptive_chairs, accessible, enough_ventilation, other_observations
		//create the associative array to be used for the select list
		$choices = array();
		foreach ($materials as $key => $row) {
			$choices[$row['room_id']] = $row['room_id'].': '.$row['year'];
		}

		//create the form for choosing an existing student to edit
        	$defaultData = array();
        	$form = $this->createFormBuilder($defaultData, array(
        		'action' => $this->generateUrl('find_school_materials', ['emisCode'=>$emisCode])))
        	->add('material','choice', array(
        		'label' => 'Choose Room',
        		'placeholder'=>'Edit Room',
        		'choices'=> $choices,
        		))
        	->getForm();

        	$form->handleRequest($request);

        	if($form->isValid()){
        		$formData = $form->getData();
        		$materialId = $formData['material'];
        		return $this->redirectToRoute('edit_school_material',array('emisCode'=>$emisCode,'materialId'=>$materialId));
        	}
        	return $this->render('school/materials/findmaterialform.html.twig', array(
        		'form' => $form->createView()));
        }
         /**
        * @Route("/school/{emisCode}/needs/{needId}", name="edit_resource_material", requirements={"needId":"new|\d+"})
        */
        public function editResourceAction(Request $request, $needId, $emisCode){
            $connection = $this->get('database_connection');
            $defaultData = array();
            $itemBeingEdited = '';
            if($needId != 'new'){/*if we are not adding a new material, fill the form fields with
            	the data of the selected learner.*/
            	$needs = $connection->fetchAll('SELECT school_has_need.*, needname FROM school_has_need NATURAL JOIN need
            		WHERE idneed = ? AND emiscode = ?', array($needId, $emisCode));
            	$defaultData = $needs[0];
      		$defaultData['year_recorded'] = new \DateTime($defaultData['year_recorded'].'-1-1');
                $defaultData['idneed_2'] = $needs[0]['idneed'];
                $itemBeingEdited = $needs[0]['idneed'].': '.$needs[0]['needname'];
            }            
            
            //generate an array to pass into form for select list options    
            $needs2 = $connection->fetchAll('SELECT idneed, needname FROM need '
                    . 'WHERE (idneed) NOT IN '
                    . '(SELECT idneed FROM school_has_need where emiscode = ?)', array($emisCode));
            
            $choices = array();
            foreach ($needs2 as $key => $row) {
                $choices[$row['idneed']] = $row['idneed'].': '.$row['needname'];
            }
            
            $form1 = $this->createForm(new ResourceRoomType($choices), $defaultData);
            
            $form1->handleRequest($request);
                        
            if($form1->isValid()){
            	$formData = $form1->getData();
                $id_need = $formData['idneed'];
                //print 'form data: '.$formData['idneed'];
                //print '; default data: '.$defaultData['idneed'].', idneed 2:'.$defaultData['idneed_2'];
                //exit;
                $need;
      		//check if this record is being edited or created anew
      		if($needId == 'new'){
                    $need = new \AppBundle\Entity\ResourceRoom();  	
      		}else{//if it is being edited, then update the records that already exist 
                    $need = $this->getDoctrine()->getRepository('AppBundle:ResourceRoom')
                            ->findOneByIdneed($defaultData['idneed_2']);              
   		}
             
                
                
                //If idneed is disabled do the right thing
                if ($needId == 'new'){
                    $need->setIdneed($this->getDoctrine()->getRepository('AppBundle:Need')
                            ->findOneByIdneed($formData['idneed']));
                }else{//pass hidden value if idneed is hidden                   
                    $need->setIdneed($this->getDoctrine()->getRepository('AppBundle:Need')
                            ->findOneByIdneed($defaultData['idneed_2']));
                }
                //$need->setDateProcured($formData['date_procured']);
                $need->setYearRecorded($formData['year_recorded']->format('Y'));
                $need->setEmiscode($this->getDoctrine()->getRepository('AppBundle:School')
                        ->findOneByEmiscode($emisCode));
                $need->setAvailable($formData['available']);
                $need->setProvidedBy($formData['provided_by']);
                $need->setQuantityInUse($formData['quantity_in_use']);
                $need->setQuantityAvailable($formData['quantity_available']);
                $need->setQuantityRequired($formData['quantity_required']);
                
                $resource = $this->getDoctrine()->getRepository('AppBundle:ResourceRoom')
                        ->findOneBy(array('idneed'=>$formData['idneed'],'emiscode'=>$emisCode));
                if ((!$resource) || ($resource && ($needId != 'new'))){
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($need);
                    $em->flush();                                                   
                    //reproduce new entered details for validation and pass appropriate messages of acknowledgement
                    if($needId == 'new'){
                        $needname = $connection->fetchAll('SELECT needname FROM need where idneed = ?', array($formData['idneed']));
                        $request->getSession()->getFlashBag()
                            ->add('resourceAdded', 'Education support item ('.$formData['idneed'].': '.$needname[0]['needname'].') added successfully');                    
                        return $this->redirectToRoute('edit_resource_material',['emisCode'=>$emisCode, 'needId'=>$id_need], 301);
                    }else {
                        $needname = $connection->fetchAll('SELECT needname FROM need where idneed = ?', array($defaultData['idneed_2']));
                        $request->getSession()->getFlashBag()
                            ->add('resourceUpdated', 'Education support item ('.$defaultData['idneed_2'].': '.$needname[0]['needname'].') updated successfully');
                        return $this->redirectToRoute('edit_resource_material',['emisCode'=>$emisCode, 'needId'=>$defaultData['idneed_2']], 301);
                    }
                }
            }
            //if this is a not new need being added, we want to make the id field uneditable
      	if($needId != 'new'){
            $readonly = true;
            $disabled = true;
            $display = false;
            //set the value to the session needId if the field is null
            //$empty_data = $needId;
      	}else{
            $readonly = false;            
            $required = true;
            $disabled = false;
            $display = true;
            //$empty_data = '';
      	}

      	return $this->render('school/materials/edit_resource_material.html.twig', array(
      		'form1'=>$form1->createView(),
      		'readonly' => $readonly,
                'display' => $display,
                'itemBeingEdited' => $itemBeingEdited,
                'disabled' => $disabled));
                //'disabled' => $disabled,'empty_data' => $empty_data));
      }
        /**
        * @Route("/school/{emisCode}/materials/{materialId}", name="edit_school_material", requirements={"materialId":"new|\S+"})
        */
        public function editMaterialAction(Request $request, $materialId, $emisCode){
            $connection = $this->get('database_connection');
            $defaultData = array();
            echo $materialId;
            if($materialId != 'new'){/*if we are not adding a new material, fill the form fields with
            	the data of the selected learner.*/
            	$materials = $connection->fetchAll('SELECT * FROM room_state
            		WHERE room_id = ? AND emiscode = ?', array($materialId, $emisCode));
            	$defaultData = $materials[0];
      		$defaultData['year'] = new \DateTime($defaultData['year'].'-1-1');
            }
       
            $form1 = $this->createForm(new RoomStateType(), $defaultData);
            
            $form1->handleRequest($request);
                        
            if($form1->isValid()){
            	$formData = $form1->getData();
                $id_room = $formData['room_id'];
                $material;
//                $update = '';
      		//check if this record is being edited or created anew
      		if($materialId == 'new'){
                    $material = new RoomState();  	
      		}else{//if it is being edited, then update the records that already exist 
                    $material = $this->getDoctrine()->getRepository('AppBundle:RoomState')->findOneByRoomId($id_room);	
      		}
 
		//set the fields for material
                $material->setRoomId($formData['room_id']);
                $material->setEmiscode($this->getDoctrine()->getRepository('AppBundle:School')->findOneByEmiscode($emisCode));
                $material->setYear($formData['year']->format('Y-m-d'));
                $material->setEnoughLight($formData['enough_light']);
      		$material->setEnoughSpace($formData['enough_space']);  
      		$material->setAdaptiveChairs($formData['adaptive_chairs']);
                $material->setAccess($formData['access']);
                $material->setEnoughVentilation($formData['enough_ventilation']);
                $material->setRoomType($formData['room_type']);               
                
                $em = $this->getDoctrine()->getManager();
                
                $roomState = $this->getDoctrine()->getRepository('AppBundle:RoomState')
                        ->findOneBy(array('roomId'=>$formData['room_id'],'emiscode'=>$emisCode));
                if ((!$roomState) || ($roomState && ($materialId != 'new'))){
                    //if object already exists but is being edited ie not new
                    $em->persist($material);
                    $em->flush();
                    if ($roomState){//Acknowledge update
                        $request->getSession()->getFlashBag()
                            ->add('roomUpdated', 'Room with id ('.$formData['room_id'].') updated successfully');
                    }else {//Acknowledge addition
                        $request->getSession()->getFlashBag()
                            ->add('roomAdded', 'Room with id ('.$formData['room_id'].') added successfully');
                    }
                    return $this->redirectToRoute('edit_school_material',['emisCode'=>$emisCode, 'materialId'=>$id_room], 301);
                }else {
                    $request->getSession()->getFlashBag()
                           ->add('roomExists', 'Room with id ('.$formData['room_id'].') already exists');
                    return $this->redirectToRoute('edit_school_material',['emisCode'=>$emisCode, 'materialId'=>'new'], 301);
                }                          
            }
            //if this is a new learner being added, we want to make the id field uneditable
      	if($materialId != 'new'){
            $readOnly = true;
      	}else{
            $readOnly = false;
      	}
      	
      	return $this->render('school/materials/edit_school_material.html.twig', array(
      		'form1'=>$form1->createView(),
      		'readonly' => $readOnly));
      }
	/**
	 *@Route("/school/{emisCode}/learners", name="school_learners", requirements={"emisCode":"\d+"})
	 */
	public function learnerAction($emisCode, Request $request){
            return $this->render('school/learners/learners_main.html.twig');
	}
	/**
	 *@Route("/findLearnerForm/{emisCode}", name="find_learner_form")
	 */
	public function findLearnerFormAction($emisCode, Request $request){//this controller will return the form used for selecting a learner
		$connection = $this->get('database_connection');
        $thisYear = date('Y');
		$students = $connection->fetchAll('SELECT lwd.idlwd,first_name,last_name FROM  lwd NATURAL JOIN lwd_belongs_to_school lbts
            LEFT JOIN school_exit ON lwd.idlwd = school_exit.idlwd AND lbts.emiscode = school_exit.emiscode AND 
            lbts.year <= school_exit.year WHERE school_exit.idlwd IS NULL AND
            school_exit.emiscode IS NULL AND lbts.emiscode = ?', array($emisCode));

		//create the associative array to be used for the select list
		$choices = array();
		foreach ($students as $key => $row) {
                    $choices[$row['idlwd']] = $row['first_name'].' '.$row['last_name'];
		}

		//create the form for choosing an existing student to edit
		$defaultData = array('learner' => $request->get('learnerId'));
		$form = $this->createFormBuilder($defaultData, array(
			'action' => $this->generateUrl('find_learner_form', ['emisCode'=>$emisCode])))
		->add('learner','choice', array(
			'label' => 'Choose Learner',
			'placeholder'=>'Choose Learner',
			'choices'=> $choices,
			))
		->getForm();

		$form->handleRequest($request);

		if($form->isValid()){
                    $formData = $form->getData();
                    $learnerId = $formData['learner'];
                    return $this->redirectToRoute('edit_learner_personal',array('emisCode'=>$emisCode,'learnerId'=>$learnerId));
		}
		return $this->render('school/learners/findlearnerform.html.twig', array(
			'form' => $form->createView()));
	}

    /**
    *@Route("/findTeacherForm/{emisCode}/", name="find_teacher_form", requirements={"teacherId":"new|\S+"})
     */
    public function findTeacherFormAction(Request $request, $emisCode){//this controller will return the form used for selecting a specialist teacher
    	$connection = $this->get('database_connection');
    	$teachers = $connection->fetchAll('SELECT idsnt,sfirst_name,slast_name FROM snt NATURAL JOIN school_has_snt
    		WHERE emiscode = ?', array($emisCode));

    	$choices = array();
    	foreach ($teachers as $key => $row) {
            $choices[$row['idsnt']] = $row['sfirst_name'].' '.$row['slast_name'];
    	}

    	//create the form for choosing an existing teacher to edit      
    	$defaultData = array();
    	$form = $this->createFormBuilder($defaultData, array(
    		'action' => $this->generateUrl('find_teacher_form', ['emisCode'=>$emisCode])))
    	->add('teacher','choice', array(
    		'label' => 'Choose Teacher',
    		'placeholder'=>'Choose Teacher',
    		'choices'=> $choices,
    		))
    	->getForm();

    	$form->handleRequest($request);

    	if($form->isValid()){
    		$formData = $form->getData();
    		$teacherId = $formData['teacher'];
    		return $this->redirectToRoute('add_teacher',array('emisCode'=>$emisCode,'teacherId'=>$teacherId));
    	}

    	return $this->render('school/specialist_teacher/findteacherform.html.twig', array(
    		'form' => $form->createView()));
    }
     /**
     * @Route("/school/{emisCode}/teachers/{teacherId}/edit", name="add_teacher", requirements ={"teacherId":"new|\S+"})      
     */
    public function addTeacherAction(Request $request, $teacherId, $emisCode){//this method will only be called through ajax

    	$connection = $this->get('database_connection');
    	$defaultData = array();

        if($teacherId != 'new'){/*if we are not adding a new learner, fill the form fields with
            the data of the selected learner.*/           
            $teacher = $connection->fetchAll('SELECT * FROM snt NATURAL JOIN school_has_snt Where idsnt = ?', array($teacherId));
            $defaultData = $teacher[0];            
            //convert the dates into their corresponding objects so that they will be rendered correctly by the form
            $defaultData['s_dob'] = new \DateTime($defaultData['s_dob']);            
            $defaultData['year_started'] = new \DateTime($defaultData['year_started'].'-1-1');/*append -1-1 at the end to make sure the string is correclty converted to 
      		a DateTime object*/

            $defaultData['year'] = new \DateTime($defaultData['year'].'-1-1');
            
            /*convert the SET value of MySQL to corresponding array in Php
            to enable correct rendering of choices in the form*/          
            //$defaultData['other_specialities'] = explode(',',$defaultData['other_specialities']);
      	}
        
      	$form2=  $this->createForm(new TeacherType(), $defaultData);
      	$form2->handleRequest($request);

      	if($form2->isValid()){
            $formData = $form2->getData();
            $teacher;
            $schoolHasSnt;

            //check if this record is being edited or created anew
            if($teacherId == 'new'){
                    $teacher = new Snt();
                    $schoolHasSnt = new SchoolHasSnt();
            }else{
                //if it is being edited, then update the records that already exist 
            	$teacher = $this->getDoctrine()->getRepository('AppBundle:Snt')->findOneByIdsnt($teacherId);
                $schoolHasSnt = $this->getDoctrine()->getRepository('AppBundle:SchoolHasSnt')->findOneBy(array('idsnt'=>$teacherId, 'emiscode'=>$emisCode, 'year'=>$defaultData['year']->format('Y')));
            }

            //set the fields for teacher
            $teacher->setEmploymentNumber($formData['employment_number']);
            $teacher->setSFirstName($formData['sfirst_name']);             
            $teacher->setSLastName($formData['slast_name']);
            $teacher->setSdob($formData['s_dob']);
            $teacher->setSSex($formData['s_sex']);
            $teacher->setQualification($formData['qualification']);            
            $teacher->setTeacherType($formData['teacher_type']);
            
            if ($formData['teacher_type'] == 'regular') {//insert CPD training data if teacher is regular
                $teacher->setCpdTraining($formData['cpd_training']);                
                $teacher->setSpeciality('');
            }else { //else insert speciality data
                $teacher->setSpeciality($formData['speciality']);
                $teacher->setCpdTraining('');
            }

            //$teacher->setOtherSpecialities($formData['other_specialities']);
            $teacher->setYearStarted($formData['year_started']->format('Y'));
            
            $snt = $this->getDoctrine()->getRepository('AppBundle:Snt')
                        ->findOneBy(array('employmentNumber'=>$formData['employment_number']));
            if ((!$snt) || ($snt && ($teacherId != 'new'))){                
                    //if object already exists but is being edited ie not new                    
                $em = $this->getDoctrine()->getManager();

                //tell the entity manager to keep track of this entity
                $em->persist($teacher);      
                $em->flush();//write all entities that are being tracked to the database
                //print 'Written teacher details to database';
 
                $schoolHasSnt->setEmiscode($this->getDoctrine()->getRepository('AppBundle:School')->findOneByEmiscode($emisCode));
                $schoolHasSnt->setIdsnt($this->getDoctrine()->getRepository('AppBundle:Snt')->findOneByIdsnt($teacher->getIdsnt()));
                $schoolHasSnt->setYear($formData['year']->format('Y'));
                
                if ($formData['teacher_type'] == 'snt') {//insert SNT type                    
                    $schoolHasSnt->setSntType($formData['snt_type']);
                } else { //else set this to null
                    $schoolHasSnt->setSntType('');
                }
                if ($formData['snt_type'] == 'Itinerant') {//insert number of visits if SNT is itinerant
                    $schoolHasSnt->setNoOfVisits($formData['no_of_visits']);
                } else { //else set it null
                    $schoolHasSnt->setNoOfVisits('');
                }

                //tell the entity manager to keep track of this entity
                $em->persist($schoolHasSnt);
                $em->flush();
                    if ($snt){//Acknowledge update
                        $request->getSession()->getFlashBag()
                            ->add('sntUpdated', 'SNT with Employee Number ('.$formData['employment_number'].') updated successfully');
                    }else {//Acknowledge addition
                        $request->getSession()->getFlashBag()
                            ->add('sntAdded', 'SNT with Employee Number ('.$formData['employment_number'].') added successfully');
                    }
                    return $this->redirectToRoute('add_teacher',['emisCode'=>$emisCode, 'teacherId'=>$teacher->getIdsnt()], 301);
                }elseif ($snt) {
                    //echo 'Else, teacherid = '.$teacherId.'snt exist';
                    //exit;
                    $request->getSession()->getFlashBag()
                            ->add('sntExists', 'SNT with Employee Number ('.$formData['employment_number'].') already exists');
                    return $this->redirectToRoute('add_teacher',['emisCode'=>$emisCode, 'teacherId'=>'new'], 301);
                }    
            
        }

      	//if this is not a new teacher being added, we want to make the id field uneditable
        //and all other fields editable
        if($teacherId != 'new'){
            $readOnly = true;            
            if ($teacher[0]['teacher_type'] == 'snt') {
                $disabledCPD = true;
                $disabledSNT = false;
                if ($teacher[0]['snt_type'] == 'Stationed'){
                    $disabledSntVisits = true;
                } else {
                    $disabledSntVisits = false;
                }
            }else {
                $disabledCPD = false;
                $disabledSNT = true;
                $disabledSntVisits = true;
            }
           
           
        }else{
            $readOnly = false;
            $disabledCPD = true;
            $disabledSNT = true;
            $disabledSntVisits = true;
        }

        return $this->render('school/specialist_teacher/add_teacher.html.twig', array(
        	'form2'=>$form2->createView(),
                'disabledSNT'=> $disabledSNT,
                'disabledCPD'=> $disabledCPD,
                'disabledSntVisits'=>$disabledSntVisits,
        	'readonly'=> $readOnly));        
    }
        
    /**
     * @Route("/school/{emisCode}/learners/{learnerId}/personal", name="edit_learner_personal", requirements ={"learnerId":"new|\d+"})
     */
    public function editLearnerPersonalAction(Request $request, $learnerId, $emisCode){

    	$connection = $this->get('database_connection');
    	$defaultData = array();
      	if($learnerId != 'new'){/*if we are not adding a new learner, fill the form fields with
            the data of the selected learner.*/
            $defaultData = $connection->fetchAssoc('SELECT * FROM lwd NATURAL JOIN guardian NATURAL JOIN lwd_belongs_to_school 
            	WHERE emiscode = ? AND idlwd = ? ORDER BY `year` DESC', array($emisCode, $learnerId));
            //convert the dates into their corresponding objects so that they will be rendered correctly by the form
            $defaultData['dob'] = new \DateTime($defaultData['dob']);
            $defaultData['gdob'] = new \DateTime($defaultData['gdob']);
      	}
      	$form1 = $this->createForm(new LearnerPersonalType(), $defaultData);

      	$form1->handleRequest($request);

      	if($form1->isValid()){
      		$formData = $form1->getData();
      		$id_lwd = $formData['idlwd'];
      		$id_guardian = $formData['idguardian'];
      		$guardian; 
      		$learner;

      		//check if this record is being edited or created anew
      		if($learnerId == 'new'){
      			$guardian = new Guardian();
      			$learner = new Lwd();
      			$learner->setIdlwd($formData['idlwd']);
      		}else{//if it is being edited, then update the records that already exist 
      			$guardian = $this->getDoctrine()->getRepository('AppBundle:Guardian')->findOneByIdguardian($id_guardian);
      			$learner = $this->getDoctrine()->getRepository('AppBundle:Lwd')->findOneByIdlwd($id_lwd);
      		}
                //set the fields for guardian
      		$guardian->setGfirstName($formData['gfirst_name']);
      		$guardian->setGlastName($formData['glast_name']);
      		$guardian->setGsex($formData['gsex']);
      		$guardian->setGaddress($formData['gaddress']);
      		$guardian->setGdob($formData['gdob']);
      		$guardian->setOccupation($formData['occupation']);
      		$guardian->setDistrict($formData['district']);  
      		//set the fields for learner
      		$learner->setFirstName($formData['first_name']);
      		$learner->setLastName($formData['last_name']);
      		$learner->setSex($formData['sex']);
      		$learner->setHomeaddress($formData['home_address']);
      		$learner->setFirstName($formData['first_name']);
      		$learner->setDob($formData['dob']);
      		$learner->setDistanceToSchool($formData['distance_to_school']);
      		$learner->setIdguardian($guardian);
      		$learner->setGuardianRelationship($formData['guardian_relationship']);
                
                //check if learnerId already exists and handle the error
                $lwdId = $this->getDoctrine()->getRepository('AppBundle:Lwd')
                        ->findOneBy(array('idlwd'=>$formData['idlwd']));
                
                if ((!$lwdId) || ($lwdId && ($learnerId != 'new'))){
                //if (($lwdId) && ($learnerId != 'new')){
                    //if object already exists but is being edited ie not new
                    //write the objects to the database
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($guardian);
                    $em->persist($learner);
                    //force the entity to use the provided learner id as opposed to an auto-generated one
                    $metadata = $em->getClassMetaData(get_class($learner));
                    $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
                    $em->flush();

                    $today = new \DateTime('y');
                    //if this is a new learner, add an entry in the lwd_belongs_to_school table, otherwise, just update the std column
                    $connection->executeQuery('INSERT IGNORE INTO lwd_belongs_to_school (idlwd, emiscode, `year`, `std`) VALUES 
                            (?,?,?,?)', [$id_lwd, $emisCode, $today->format('Y-m-d'), $formData['std']]);      		
                    
                    if ($lwdId){//Acknowledge update
                        $request->getSession()->getFlashBag()
                            ->add('lwdUpdated', 'LWD with id ('.$formData['idlwd'].') updated successfully');
                    }else {//Acknowledge addition
                        $request->getSession()->getFlashBag()
                            ->add('lwdAdded', 'LWD with id ('.$formData['idlwd'].') added successfully');
                    }
                    return $this->redirectToRoute('edit_learner_disability',['emisCode'=>$emisCode, 'learnerId'=>$id_lwd], 301);
                }else {//Lwd already exists in DB
                    $request->getSession()->getFlashBag()
                            ->add('lwdExists', 'LWD with id ('.$formData['idlwd'].') already exists');
                    return $this->redirectToRoute('edit_learner_personal',['emisCode'=>$emisCode, 'learnerId'=>'new'], 301);
                } 
      	}
      	//if this is a new learner being added, we want to make the id field uneditable
      	if($learnerId != 'new'){
            $readOnly = true;
      	}else{
            $readOnly = false;
      	}
      	
      	return $this->render('school/learners/edit_learner_personal.html.twig', array(
      		'form1'=>$form1->createView(),
      		'readonly' => $readOnly));
      }
    /**
     * @Route("/school/{emisCode}/learners/{learnerId}/need", name="edit_learner_disability", requirements ={"learnerId":"new|\d+"})
     */
    public function editLearnerDisabilityAction(Request $request, $learnerId, $emisCode){

    	$forms = array(); //array to keep the forms: there could be more than one disability form for a learner
    	$connection = $this->get('database_connection'); 
    	$disabilities = $connection->fetchAll("SELECT * FROM disability");
        $disabilityNames = array(); //will be used in the template to provide pagination for the disabilities

    	if($learnerId != 'new'){
    		$learnerDisabilities = $connection->fetchAll("SELECT * FROM lwd_has_disability WHERE idlwd = ?", array(
    			$learnerId));
    		if($learnerDisabilities){
    			$formCounter = 1;
    			//prepare SQL statements to be executed with each iteration
    			$levelsStmt = $connection->prepare("SELECT idlevel, level_name FROM disability_has_level NATURAL JOIN level 
    					WHERE iddisability = ?");
    			$needsStmt = $connection->prepare("SELECT idneed, needname FROM disability_has_need NATURAL JOIN need 
    					WHERE iddisability = ?");
    			$needsRowsStmt = $connection->prepare("SELECT idneed FROM lwd_has_disability_has_need WHERE idlwd = ? 
    					AND iddisability = ?");

                $dataConverter = $this->get('data_converter');
    			//iterate over each disability for this learner
    			foreach($learnerDisabilities as $disability){
                    $disabilityNames[] = $dataConverter->selectFromArray($disabilities, 'iddisability', $disability['iddisability'])[0]['disability_name'];
    				//get the levels to show in the form for this disability
    				$levelsStmt->bindParam(1, $disability['iddisability']);
    				$levelsStmt->execute();
    				$levels = $levelsStmt->fetchAll();

    				$disability['identification_date'] = new \DateTime($disability['identification_date']);
    				$disability['iddisability_2'] = $disability['iddisability'];//set default data for the hidden field since the true iddisability will be disabled
                    //get the needs for this disability
                    $needsStmt->bindParam(1, $disability['iddisability']);
                    $needsStmt->execute();
                    $needs = $needsStmt->fetchAll();
                    //get the needs that the learner has access to for this disability
                    $needsRowsStmt->bindParam(1, $learnerId);
                    $needsRowsStmt->bindParam(2, $disability['iddisability']);
                    $needsRowsStmt->execute();
                    $availableNeedsRows = $needsRowsStmt->fetchAll();
                    //get the ids of all the available needs as a single array
                    $availableNeeds = array_column($availableNeedsRows, 'idneed');
                    $disability['needs'] = $availableNeeds;
    				$forms[] = $this->createForm(new LearnerDisabilityType($disabilities, $levels, $formCounter, $needs), $disability); 

    				$formCounter++;
    			}
    			$levelsStmt->closeCursor();
    			$needsStmt->closeCursor();
    			$needsRowsStmt->closeCursor();
    		}
    		//process each of the forms
    		$formCounter = 1;
    		foreach($forms as $form){
    			$form->handleRequest($request);
    			if($form->isValid()){
    				$formData = $form->getData();
    				$em = $this->getDoctrine()->getManager();
    				$lwdHasDisability = $em->getRepository('AppBundle:LwdHasDisability')->findOneBy([
    					'idlwd'=>$learnerId,
    					'iddisability' =>$formData['iddisability_2']
    					]
					);
					if($form->get('remove')->isClicked()){//if the remove button was clicked for this record
                                            $em->remove($lwdHasDisability);
                                            //delete needs records
                                            $connection->executeQuery('DELETE FROM lwd_has_disability_has_need WHERE iddisability = ? 
                                            AND idlwd = ?', array($formData['iddisability_2'], $learnerId));
                                            $message = "Disability/Special need record removed";
                                            $messageType = 'recordRemovedMessage';
					}
					else{
                                            $lwdHasDisability->setIdentifiedBy($formData['identified_by']);
                                            $lwdHasDisability->setIdentificationDate($formData['identification_date']);
                                            $lwdHasDisability->setCaseDescription($formData['case_description']);
                                            if($formData['idlevel'] != null){
                                               $lwdHasDisability->setIdlevel($em->getReference('AppBundle:Level', $formData['idlevel']));
                                            }
                                            $em->persist($lwdHasDisability);
                                            $dataConverter = $this->get('data_converter');
                                            $selectedNeeds = $dataConverter->arrayRemoveQuotes($formData['needs']);                 
                                            $commaString = $dataConverter->convertToCommaString($selectedNeeds); /*convert array 
                                            of checked values to comma delimited string */
                                            $connection->executeQuery('DELETE FROM lwd_has_disability_has_need WHERE iddisability = ? 
                                                AND idlwd = ? AND idneed NOT IN (?)', array($formData['iddisability_2'], $learnerId, $commaString));/*delete all records in the db
                                            that are not checked on the form*/
                                            //write the records for needs available to this learner if the records do not already exist in the db
                                            $writeNeeds = $connection->prepare('INSERT IGNORE INTO lwd_has_disability_has_need SET idlwd = ?, 
                                                iddisability = ?, idneed = ?');
                                            $writeNeeds->bindParam(1, $learnerId);
                                            $writeNeeds->bindParam(2, $formData['iddisability_2']);
                                            //iterate over array of needs checked on the form and add each one to the database
                                            foreach($selectedNeeds as $selectedNeed){
                                                $writeNeeds->bindParam(3, $selectedNeed);
                                                $writeNeeds->execute();
                                            }
                                            $writeNeeds->closeCursor();
                                            $message = "Disability/Special need record updated";
                                            $messageType = $formCounter;
					}
					$em->flush();

					$this->addFlash($messageType, $message);
					return $this->redirectToRoute('edit_learner_disability', ['learnerId'=>$learnerId,'emisCode'=>$emisCode], 301);
    			}
    			$formCounter++;
    		}
    	}

    	$levels2 = array();
        $hasLevels = false;
    	if($this->get('session')->getFlashBag()->has('levels')){
            $hasLevels = true;
    		$levels2 = $this->get('session')->getFlashBag()->get('levels');
    	}
        //the form for adding a new disability to this learner's profile
    	$newForm = $this->createForm(new LearnerDisabilityType($disabilities, $levels2, "", array(), false));

    	$newForm->handleRequest($request);
    	if($newForm->isValid()){
    		$em = $this->getDoctrine()->getManager();
    		$formData = $newForm->getData();
    		$lwdHasDisability = new LwdHasDisability();
    		$lwdHasDisability->setIdlwd($em->getReference('AppBundle:Lwd', $learnerId));
    		$idDisability = $this->getDoctrine()->getRepository('AppBundle:Disability')->findOneByIddisability($formData['iddisability']);
    		$lwdHasDisability->setIddisability($idDisability);
    		$lwdHasDisability->setIdentifiedBy($formData['identified_by']);
    		$lwdHasDisability->setIdentificationDate($formData['identification_date']);
    		$lwdHasDisability->setCaseDescription($formData['case_description']);
    		if($hasLevels){
	    		$lwdHasDisability->setIdlevel($em->getReference('AppBundle:Level', $formData['idlevel']));
	    	} 

                //check if disability already exists in db
                $lwdDisability = $this->getDoctrine()->getRepository('AppBundle:LwdHasDisability')
                        ->findOneBy(array('idlwd'=>$learnerId,'iddisability'=>$formData['iddisability']));
                if (!$lwdDisability){//if disability does not exist
                    $em->persist($lwdHasDisability);
                    $em->flush();

                    $message = "New disability/special need record added for learner ".$learnerId;
                    $this->addFlash('disabilityAddedMessage', $message);                    
                } else {
                    $message = "Disability/special need record already exists for learner ".$learnerId;
                    $this->addFlash('disabilityExists', $message);
                }
                return $this->redirectToRoute('edit_learner_disability', ['learnerId'=>$learnerId,'emisCode'=>$emisCode], 301);
    	}

    	//create a view of each of the forms
    	foreach($forms as &$form){
    		$form = $form->createView();
    	}
    	return $this->render('school/learners/edit_learner_disability.html.twig', array(
    		'forms' => $forms, 'newform' => $newForm->createView(), 
            'disabilityNames' => $disabilityNames)
    	);
    } 
    /**
     * @Route("/school/{emisCode}/learners/{learnerId}/exit", name="learner_exit", requirements ={"learnerId":"new|\d+"})
     */
    public function learnerExitAction(Request $request, $learnerId, $emisCode){
        $em = $this->getDoctrine()->getManager();
        $form = $this->createForm(new LearnerExitType);

        $form->handleRequest($request);
        if($form->isValid()){
            $formData = $form->getData();
            $exit = new SchoolExit();
            $exit->setIdlwd($em->getReference('AppBundle:Lwd', $learnerId));
            $exit->setEmiscode($em->getReference('AppBundle:School', $emisCode));
            $exit->setReason($formData['reason']);
            $today = new \DateTime('y');
            $exit->setYear($today->format('Y-m-d'));
            if($formData['reason'] != "other"){
                $exit->setOtherReason($formData['other_reason']);
            }

            $em->persist($exit);
            $em->flush();

            $schoolName = "";
            if($request->getSession()->has('emiscode')){
                $schoolName = ' from '.$request->getSession()->get('school_name');
            }
            $this->addFlash('exitMessage', 'Exit of student '.$learnerId.$schoolName.' recorded');
            return $this->redirectToRoute('school_learners', ['emisCode' => $emisCode], 301);
        }

        return $this->render('school/learners/learner_exit.html.twig', array(
                'form' => $form->createView()
            )
        );

    }
  
    /**
     * @Route("/populateschools/{districtId}", name="populate_schools", requirements ={"iddistrict":"\d+"}, condition="request.isXmlHttpRequest()", options={"expose":true})
     */
    public function populateSchoolsAction($districtId){
    	$connection = $this->get('database_connection');
    	$schools = $connection->fetchAll("SELECT emiscode, school_name FROM school WHERE iddistrict = ?", array($districtId));
    	$html = '';
    	if($schools){
    		$this->get('session')->getFlashBag()->set('schools', $schools);
    		foreach($schools as $key => $school){
    			$html .= '<option value="'.$school['emiscode'].'">'.$school['emiscode'].': '.$school['school_name'].'</option>';
    		}
    	}
    	return new Response($html);
    }
    /**
     * @Route("/populatelearners/{schoolId}", name="populate_learners", requirements ={"emiscode":"\d+"}, condition="request.isXmlHttpRequest()", options={"expose":true})
     */
    public function populateLearnersAction($schoolId){
    	$connection = $this->get('database_connection');
    	$learners = $connection->fetchAll("SELECT idlwd, first_name, last_name FROM lwd_belongs_to_school NATURAL JOIN lwd WHERE emiscode = ?", array($schoolId));
    	$html = '';
    	if($learners){
    		$this->get('session')->getFlashBag()->set('learners', $learners);
    		foreach($learners as $key => $learner){
    			$html .= '<option value="'.$learner['idlwd'].'">'.$learner['idlwd'].': '.$learner['first_name'].' '.$learner['last_name'].'</option>';
    		}
    	}
    	return new Response($html);
    }
    //controller called through ajax to autopopulate level select list
    /**
     * @Route("/populatelevels/{disabilityId}", name="populate_levels", requirements ={"iddisability":"\d+"}, condition="request.isXmlHttpRequest()", options={"expose":true})
     */
    public function populateLevelsAction($disabilityId){
    	$connection = $this->get('database_connection');
    	$levels = $connection->fetchAll("SELECT idlevel, level_name FROM disability_has_level NATURAL JOIN level 
    					WHERE iddisability = ?", array($disabilityId));
    	$html = '';
    	if($levels){
    		$this->get('session')->getFlashBag()->set('levels', $levels);
    		foreach($levels as $key => $level){
    			$html .= '<option value="'.$level['idlevel'].'">'.$level['level_name'].'</option>';
    		}
    	}
    	return new Response($html);
    }
     /**
     * @Route("/school/{emisCode}/learners/{learnerId}/performance/{record}", name="edit_learner_performance", requirements ={"learnerId":"new|\d+", "record":"update|add"}, defaults={"record":"update"})
     */
     public function editLearnerPerformanceAction(Request $request, $learnerId, $record, $emisCode){
     	$connection = $this->get('database_connection');
    	//if this is not a new record, then create some default data.
     	$action = "added";
     	$mode = "Editing last performance record";
     	$performanceRecord;

     	$defaultData = array();
    	if($learnerId != 'new' && $record == 'update'){//if we are not adding a new learner or a new performance record for an existing learner
    		//fetch the last record added for this learner
    		$last_record = $connection->fetchAll("SELECT * FROM performance WHERE idlwd = ? ORDER BY year DESC,
    			term DESC LIMIT 1", array($learnerId));
    		if($last_record){//if a previous record exists for this learner
    			$defaultData = $last_record[0];
    			$defaultData['year'] = new \DateTime($defaultData['year'].'-1-1');
    			$performanceRecord = $this->getDoctrine()->getRepository('AppBundle:Performance')->findOneByRecId($defaultData['rec_id']);
    			$action = "updated";
    		}else{
    			$performanceRecord = new Performance();
    			$mode = "Adding new performance record";
    		}

    	}else{
    		$performanceRecord = new Performance();
    		$mode = "Adding new performance record";
    	}
    	$message = "";

    	$form = $this->createForm(new LearnerPerformanceType(), $defaultData);
    	$form->handleRequest($request);

    	if($form->isValid()){
    		$formData = $form->getData();

    		$performanceRecord->setIdlwd($this->getDoctrine()->getRepository('AppBundle:Lwd')->findOneByIdlwd($learnerId));
    		$performanceRecord->setStd($formData['std']);
    		$performanceRecord->setYear($formData['year']->format('Y-m-d'));
    		$performanceRecord->setTerm($formData['term']);
    		$performanceRecord->setGrade($formData['grade']);
    		$performanceRecord->setTeachercomment($formData['teachercomment']);
    		$performanceRecord->setEmiscode($this->getDoctrine()->getRepository('AppBundle:School')->findOneByEmiscode($emisCode));

    		$em = $this->getDoctrine()->getManager();
    		$em->persist($performanceRecord);
    		$em->flush();
    		$message = "Performance record ".$action;
    		$this->addFlash('message',$message);
    		return $this->redirectToRoute('edit_learner_performance', array(
    			'form' => $form,
    			'emisCode'=> $emisCode,
    			'learnerId' => $learnerId,
    			),
    		301
    		);
    	}
    	return $this->render('school/learners/edit_learner_performance.html.twig',array(
    		'form' => $form->createView(),
    		'mode' => $mode,)
    	);
    }

    /**
	 *@Route("/school/{emisCode}/teachers", name="school_teachers", requirements={"emisCode":"\d+"}, options={"expose"= true})
	 */
    public function teacherAction($emisCode, Request $request){

    	return $this->render('school/specialist_teacher/teachers_main.html.twig');
    }


}

?>
