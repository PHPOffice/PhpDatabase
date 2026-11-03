import com.healthmarketscience.jackcess.Database;
import com.healthmarketscience.jackcess.DatabaseBuilder;
import com.healthmarketscience.jackcess.Relationship;
import com.healthmarketscience.jackcess.Row;
import com.healthmarketscience.jackcess.Table;
import com.healthmarketscience.jackcess.query.Query;
import java.io.File;

/**
 * Opens each Access file (ACCDB, MDB) with Jackcess and reads every row, relationship and query:
 * any corruption of the pages, the indexes or the system tables ends in an exception.
 *
 * Usage: java -cp <jars> AccessValidator.java file.accdb [file.mdb ...]
 */
public class AccessValidator {
    public static void main(String[] args) throws Exception {
        if (args.length == 0) {
            throw new IllegalArgumentException("No Access file to validate");
        }
        for (String path : args) {
            try (Database db = new DatabaseBuilder(new File(path)).setReadOnly(true).open()) {
                System.out.println(path + " (" + db.getFileFormat() + ")");
                for (String name : db.getTableNames()) {
                    Table table = db.getTable(name);
                    int rows = 0;
                    for (Row row : table) {
                        rows++;
                    }
                    if (rows != table.getRowCount()) {
                        throw new IllegalStateException(name + ": " + rows + " rows read, " + table.getRowCount() + " declared");
                    }
                    System.out.println("  table " + name + ": " + table.getColumnCount() + " columns, " + rows + " rows, " + table.getIndexes().size() + " indexes");
                }
                for (Relationship relationship : db.getRelationships()) {
                    System.out.println("  relationship " + relationship.getName());
                }
                for (Query query : db.getQueries()) {
                    System.out.println("  query " + query.getName() + ": " + query.toSQLString().replaceAll("\\s+", " "));
                }
            }
        }
    }
}
