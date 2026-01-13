public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add a column for money (up to 99,999.99)
            $table->decimal('penalty_amount', 8, 2)->default(0);
        });
    }