<?php namespace Rackage;

class Validate {
    
    public static function make($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            // Check required
            if (strpos($rule, 'required') !== false) {
                if (empty($data[$field])) {
                    $errors[$field][] = "$field is required";
                }
            }
            
            // Check email
            if (strpos($rule, 'email') !== false) {
                if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "$field must be valid email";
                }
            }
            
            // Check min length
            if (preg_match('/min:(\d+)/', $rule, $matches)) {
                $min = $matches[1];
                if (strlen($data[$field]) < $min) {
                    $errors[$field][] = "$field must be at least $min characters";
                }
            }
            
            // Check max length
            if (preg_match('/max:(\d+)/', $rule, $matches)) {
                $max = $matches[1];
                if (strlen($data[$field]) > $max) {
                    $errors[$field][] = "$field must not exceed $max characters";
                }
            }
        }
        
        return empty($errors) ? true : $errors;
    }
}