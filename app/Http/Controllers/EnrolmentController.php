<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Requests\CreateRegisterRequest;
use Auth;
use DateTime;
use App\User;
use App\Role;
use App\Enrolment;
use Mail;
use App\House;
use App\Track;
use Config;

class EnrolmentController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $user = Auth::user();
        return $user->is_admin ? response()->json(['enrolments'=>Enrolment::with(['users','roles','houses','purchaser'])->get(),'message'=>'Enrolment retrieved', 'code'=> 201],201) : response()->json(['message' =>'Not authorized to view enrolment details', 'code'=>401], 401);
    }


    public function create(){
        $user=Auth::user();
        return $user->is_admin ? response()->json(['users'=>\App\User::select('id','name','email')->get(), 'houses'=>House::select('id','house','currency','price')->get(), 'roles'=>\App\Role::select('id','role')->get(), 'currency'=>['USD','SGD'],'message'=>'Enrolments fields retrieved','code'=>201],201): response()->json(['message'=>'Not authorized to enrol.','code'=>401],401);
    }

    /* Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateRegisterRequest $request)
    { 
        $user = Auth::user();
        $date = new DateTime('now');
        $role_to_enrol = Role::where('role','LIKE',$request->role)->first();
        $class_to_enrol = \App\House::findorfail($request->house_id);
        if (!$request->user) {
            $enrol_user = $user;
        } else {                            // not enrolling yourself
            $enrol_user = User::where('name','LIKE', $request->user)->first();
//            $enrol_user = User::find($request->user_id);
            $most_powerful = $user->enrolledClasses()->whereHouseId($request->house_id)->with('roles')->min('role_id');
            if (!$most_powerful || $most_powerful > $role_to_enrol->id || !$user->is_admin) {        // administrator 
                return response()->json(['message'=>'No authorization to enrol', 'code'=>203], 203);
            }
        }
        $mastercode = null;
        $mastercodemsg = null;
        if ($request->places_alloted>0){
            $mastercode = rand ( 10000000, 99999999);
            $mastercodemsg = 'Your mastercode is '.$mastercode.', which can be used for enrollment of '.$request->places_alloted.' student(s).  Once your student logs in for the first time, the enrolment starts. Please instruct your student to proceed to quiz.all-gifted.com to enrol for the course and begin a diagnostic test. Should you have any queries, please do not hesitate to contact us at info.allgifted@gmail.com. Thank you.';
        }

        $register = Enrolment::firstOrNew(['user_id'=>$enrol_user->id, 'house_id'=>$request->house_id, 'role_id'=>$role_to_enrol->id]);
        $register->fill(['start_date'=>new DateTime('now'),'expiry_date'=>$date->modify('+1 year'), 'payment_email'=>$user->email, 'purchaser_id'=>$user->id, 'mastercode'=>$mastercode, 'amount_paid'=>$request->amount_paid, 'places_alloted'=>$request->places_alloted, 'transaction_id'=>$request->transaction_id, 'currency_code'=>$request->currency_code, 'payment_status'=>'paid'])->save();
        $note = 'Dear '.$user->firstname.',<br><br>Thank you for signing up with us on the '.$class_to_enrol->description.' program! <br><br>'.$mastercodemsg.'<i>This is an automated machine generated by the All Gifted System.</i>';

        Mail::send([],[], function ($message) use ($user,$note) {
            $message->from('info.allgifted@gmail.com', 'All Gifted Admin')
                    ->to($user->email)->cc('math@allgifted.com')
                    ->subject('Thank you for registration')
                    ->setBody($note, 'text/html');
        });
        return response()->json(['message' => 'Registration successful. Within an hour, you should receive an email at '.$user->email.'. '.$mastercodemsg, 'enrolment'=> $register,
            'places_alloted'=>$request->places_alloted, 'code'=>201]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function user_houses() {
        $user = Auth::user();
        $houses = $user->studentHouse()->with(['tracks.checkedSkills.skill_maxile','tracks.checkedSkills.links','tracks.track_passed'])->get();

        foreach ($houses as $house) {
          $house['course_maxile'] = (int)min($user->maxile_level,$house->course->end_maxile_score);//Enrolment::whereUserId($user->id)->whereHouseId($house->id)->whereRoleId(6)->pluck('progress')->first();
          $house['accuracy'] = $user->accuracy();
          $house['tracks_passed'] = count($house->tracks->intersect($user->tracksPassed));
          $house['total_tracks'] = count($house->tracks);
          $house['skill_passed'] = count(\App\Skill::whereIn('id', \App\Skill_Track::whereIn('track_id',$house->tracks()->pluck('id'))->pluck('skill_id'))->get()->intersect($user->skill_user()->whereSkillPassed(TRUE)->get()));
          $house['total_skills'] = count(\App\Skill_track::whereIn('track_id', $house->tracks()->pluck('id'))->get());
          $house['radarChartLabels'] = $user->fields->pluck('field');
          $house['radarChartData'] = [['data'=> $house['radarChartLabels']? \App\FieldUser::whereUserId($user->id)->orderBy('field_id')->pluck('field_maxile'):0, 'label'=>'Field Maxile']];
          $house['target_score'] = $house->course()->pluck('end_maxile_score')->first(); 
        }

        return response()->json(['message' =>'Successful retrieval of enrolment.', 'houses'=>$houses, 'code'=>201], 201);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function teacher_houses() {
        $user = Auth::user();
        if ($user->is_admin){
            $houses = $user->roleHouse()->with('roles')->with(['framework','tracks.owner','tracks.skills.links','tracks.skills.user','tracks.field','tracks.status','tracks.level','enrolledStudents'])->get();
        } else {
            $houses = House::with('roles')->with(['framework','tracks.owner','tracks.skills.user','tracks.field','tracks.status','tracks.level','enrolledStudents'])->get();
        }

        foreach ($houses as $class) {
            $class['average_progress']=$class->studentEnrolment->avg('progress');
            $class['lowest_progress'] = $class->studentEnrolment->min('progress');
            $class['highest_progress'] = $class->studentEnrolment->max('progress');
            $class['students_completed_course'] = $class->studentEnrolment->where('expiry_date','<', new DateTime('today'))->count();         
            $class['chartdata']=[$class->studentEnrolment()->where('progress','<', $class->underperform)->count(),$class->studentEnrolment()->where('progress','>=', $class->underperform)->where('progress', '<',$class->overperform)->whereRoleId(6)->count(),$class->studentEnrolment()->where('progress','>=', $class->overperform)->count()];
            $class['tracksdata'] = $class->tracks()->pluck('track');
//            $class['barchartdata'] = [['data'=> \App\TrackUser::whereIn('track_id', House::find(1)->tracks()->pluck('id'))->whereIn('user_id', House::find(1)->enrolledStudents()->pluck('id'))->avg('track_maxile') ? \App\TrackUser::whereIn('track_id', House::find(1)->tracks()->pluck('id'))->whereIn('user_id', House::find(1)->enrolledStudents()->pluck('id'))->avg('track_maxile'):0 , 'label'=>'Average Maxile']];
        }

        return response()->json(['message' =>'Successful retrieval of teacher enrolment.', 'houses'=>$houses, 'code'=>201], 201);
    }

 /**

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Enrolment $enrolment) {
        return response()->json(['message' =>'Successful retrieval of enrolment.', 'enrolment'=>$enrolment, 'code'=>201], 201);
    }

   /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Enrolment $enrolment)
    {
        $logon_user = Auth::user();
        if ($logon_user->id != $enrolment->user_id && !$logon_user->is_admin) {            
            return response()->json(['message' => 'You have no access rights to update enrolment.','code'=>401], 401);     
        }
        $enrolment->fill($request->all())->save();
        
        return response()->json(['message'=>'Enrolment updated','enrolment' => $enrolment, 'code'=>201], 201);
    }


     /**
     * Remove the specified resource from storage.
     *
     * @param  Enrolment  $enrolment
     * @return \Illuminate\Http\Response
     */
    public function destroy(Enrolment $enrolment)
    {
        $logon_user = Auth::user();
        if (!$logon_user->is_admin) {            
            return response()->json(['message' => 'You have no access rights to delete enrolment','code'=>401], 401);
        } 
        $enrolment->delete();
        return response()->json(['message'=>'This enrolment has been deleted','code'=>201], 201);
    }

}