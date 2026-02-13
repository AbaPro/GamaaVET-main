-- Make vendor address fields nullable
-- Safe migration: only modifies columns to allow NULL

ALTER TABLE `vendor_addresses`
    MODIFY `address_line1` varchar(100) DEFAULT NULL,
    MODIFY `city` varchar(50) DEFAULT NULL,
    MODIFY `state` varchar(50) DEFAULT NULL,
    MODIFY `postal_code` varchar(20) DEFAULT NULL,
    MODIFY `country` varchar(50) DEFAULT NULL;
