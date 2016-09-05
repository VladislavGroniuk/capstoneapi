<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use DateTime;

class Test extends Model
{
    use RecordLog;
    
    protected $hidden = ['user_id', 'created_at','updated_at','pivot'];
    protected $fillable = ['test', 'description', 'diagnostic', 'number_of_tries_allowed','start_available_time', 'end_available_time','due_time','which_result','image', 'status_id'];

    //relationship
    public function questions(){
        return $this->belongsToMany(Question::class, 'question_user')->withPivot('correct','question_answered','answered_date', 'attempts', 'user_id')->withTimestamps();
    }

    public function skills() {
        return $this->belongsToMany(Skill::class)->withTimestamps();
    }

    public function testee(){
        return $this->belongsToMany(User::class)->withPivot('test_completed','completed_date', 'result', 'attempts')->withTimestamps();
    }

    public function tester(){
        return $this->belongsTo(User::class);
    }

    public function houses(){
        return $this->belongsToMany(Test::class)->withTimestamps();
    }

    public function results(){
        return $this->morphMany(Result::class, 'assessment');
    }

    public function activities(){
        return $this->morphMany(Activity::class, 'classwork');
    }

    public function uncompletedQuestions(){
        return $this->questions()->whereQuestionAnswered(FALSE);
    }

    public function attempts($userid){
        return $this->testee()->whereUserId($userid)->select('attempts')->first()->attempts;
    }

    public function markTest($userid){
        return count($this->questions) ? $this->questions()->sum('correct')/count($this->questions) * 100 : 0;
    }

    public function fieldQuestions($user){
        if (!count($this->uncompletedQuestions)) {    // no more questions
            $new_maxile = round($user->calculateUserMaxile()/100)*100;
            if (!$this->diagnostic && !count($this->questions)) {              // start a new test
                // search for tracks not passed
                return $user->failedTracks;
            }
            if ($user->maxile_level>0 && $new_maxile == floor($user->maxile_level/100)*100) { // ends a test
                count($this->questions) < $this->questions()->sum('question_answered') ? null:
                $this->testee()->updateExistingPivot($user->id, ['test_completed'=>TRUE, 'completed_date'=>new DateTime('now'), 'result'=>$result = $this->markTest($user->id), 'attempts'=>$this->attempts($user->id) +1]);
                return response()->json(['message' => 'Test completed successfully', 'test'=>$this->id, 'percentage'=>$result, 'score'=>$user->calculateUserMaxile(), 'maxile'=> $user->calculateUserMaxile(),'code'=>206], 206);                
            } elseif ($this->diagnostic || !count($this->questions)) {  // diagnostic test
                $level = !count($this->questions) ? Level::find(2): // Level::myLevel()->first()
                Level::whereLevel($new_maxile)->first();  
                
                foreach ($level->tracks as $track){
                    $track->users; 
                    $track->users()->sync([$user->id], false);          //log tracks for user
                    $new_question = !$this->diagnostic ? Question::whereIn('skill_id', $track->skills->lists('id'))->inRandomOrder()->first() : Question::whereIn('skill_id', $track->skills->lists('id'))->orderBy('difficulty_id', 'desc')->first();
                    $new_question ? $new_question->assigned($user, $this) : null;
                }
            }
        } 
        $questions = $this->uncompletedQuestions()->get();
        $test_questions = count($questions)< 6 ? $questions : $questions->take(5);
        return response()->json(['message' => 'Request executed successfully', 'test'=>$this->id, 'questions'=>$test_questions, 'code'=>201]);
    }
}