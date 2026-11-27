package mx.com.avba.caseta;

import java.text.Normalizer;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.Deque;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.regex.PatternSyntaxException;

/**
 * Encuentra el NOMBRE y el CÓDIGO NUMÉRICO en el texto que el reconocimiento
 * del teléfono leyó de una tarjeta de identificación (gafete).
 *
 * No usa Android: recibe renglones de texto con su tamaño y posición y regresa
 * lo que encontró. Así se puede probar en la computadora y ajustar con reglas
 * (palabras clave, palabras que no son nombre, cuántos dígitos tiene el código
 * o un patrón propio) sin tocar la cámara.
 */
public final class AnalizadorTarjeta {

    private AnalizadorTarjeta() {}

    /** Un renglón leído: texto, posición (arriba, izquierda), alto y ancho en píxeles. */
    public static final class Linea {
        public final String texto;
        public final int arriba, izquierda, alto, ancho;
        /** Cada carácter del texto (sin los espacios) con su caja en la imagen; vacío si el reconocimiento no la da. */
        public final List<Simbolo> simbolos;
        public Linea(String texto, int arriba, int izquierda, int alto) { this(texto, arriba, izquierda, alto, 0); }
        public Linea(String texto, int arriba, int izquierda, int alto, int ancho) { this(texto, arriba, izquierda, alto, ancho, null); }
        public Linea(String texto, int arriba, int izquierda, int alto, int ancho, List<Simbolo> simbolos) {
            this.texto = texto == null ? "" : texto;
            this.arriba = arriba; this.izquierda = izquierda; this.alto = Math.max(1, alto); this.ancho = Math.max(0, ancho);
            this.simbolos = simbolos == null ? Collections.<Simbolo>emptyList() : simbolos;
        }

        /** La caja de cada carácter del texto, en su misma posición (null en los espacios). Si los caracteres
         *  no coinciden con el texto, ninguna: entonces se decide sólo por el texto. */
        List<Simbolo> cajas() {
            Simbolo[] c = new Simbolo[texto.length()];
            if (simbolos.isEmpty()) return Arrays.asList(c);
            int k = 0;
            for (int i = 0; i < texto.length(); i++) {
                char ch = texto.charAt(i);
                if (Character.isWhitespace(ch)) continue;
                if (k >= simbolos.size() || simbolos.get(k).c != ch) return Arrays.asList(new Simbolo[texto.length()]);
                c[i] = simbolos.get(k++);
            }
            return k == simbolos.size() ? Arrays.asList(c) : Arrays.asList(new Simbolo[texto.length()]);
        }
    }

    /** Un carácter leído con su caja en la imagen (izquierda, arriba, derecha, abajo, en píxeles). Con ella se
     *  reconoce la raya blanca de la impresora en cualquier ID: es mucho más delgada que cualquier número,
     *  también que un «1» (el «1» impreso lleva su bandera y es casi tan ancho como los demás). */
    public static final class Simbolo {
        public final char c;
        public final int izquierda, arriba, derecha, abajo;
        public Simbolo(char c, int izquierda, int arriba, int derecha, int abajo) {
            this.c = c; this.izquierda = izquierda; this.arriba = arriba; this.derecha = derecha; this.abajo = abajo;
        }
        int ancho() { return Math.max(0, derecha - izquierda); }
    }

    /** Lo que se encontró en una lectura. «enRecuadro»: el texto que se leyó junto a «ID:» (para decirle al guardia qué ve). */
    public static final class Lectura {
        public final String codigo, nombre, enRecuadro;
        public final int puntaje;
        public Lectura(String codigo, String nombre, int puntaje) { this(codigo, nombre, puntaje, null); }
        public Lectura(String codigo, String nombre, int puntaje, String enRecuadro) {
            this.codigo = codigo; this.nombre = nombre; this.puntaje = puntaje; this.enRecuadro = enRecuadro;
        }
        @Override public String toString() { return "codigo=" + codigo + " nombre=" + nombre + " puntaje=" + puntaje; }
    }

    /** Reglas de la tarjeta. Los valores por omisión sirven para un gafete con nombre y número de empleado. */
    public static final class Reglas {
        public int minDigitos = 4, maxDigitos = 12, longitud = 0;
        public Pattern patron = null;                  // si se da, el código es el grupo 1 (o todo) de este patrón
        public Set<String> claves = conjunto(
            "NO", "NUM", "NUMERO", "N", "ID", "EMPLEADO", "EMP", "FOLIO", "CODIGO", "COD", "CLAVE", "NOMINA",
            "FICHA", "GAFETE", "CREDENCIAL", "MATRICULA", "PERSONAL", "EXPEDIENTE", "NO.", "#");
        public Set<String> noNombre = conjunto(
            "QUANTUM", "ENERGY", "ENERGIA", "POWER", "GAFETE", "CREDENCIAL", "IDENTIFICACION", "EMPLEADO", "EMPLEADA",
            "NOMBRE", "PUESTO", "AREA", "DEPARTAMENTO", "DEPTO", "VIGENCIA", "VIGENTE", "VALIDO", "VALIDA", "VENCE", "EXPIRA",
            "CONTRATISTA", "VISITANTE", "PERSONAL", "FIRMA", "TITULAR", "NUMERO", "CODIGO", "FOLIO", "CLAVE", "TEL",
            "TELEFONO", "EMERGENCIA", "SANGRE", "TIPO", "NSS", "IMSS", "CURP", "RFC", "PLANTA", "SEGURIDAD", "ACCESO",
            "MEXICO", "SA", "CV", "S.A.", "C.V.", "INGENIERO", "INGENIERA", "ING", "TECNICO", "TECNICA", "SUPERVISOR",
            "SUPERVISORA", "OPERADOR", "OPERADORA", "GERENTE", "JEFE", "JEFA", "COORDINADOR", "COORDINADORA", "AUXILIAR",
            "ANALISTA", "ASISTENTE", "ESPECIALISTA", "MANTENIMIENTO", "PRODUCCION", "CALIDAD", "ALMACEN", "ADMINISTRACION",
            "OPERACIONES", "LOGISTICA", "AMBIENTAL", "ELECTRICO", "ELECTRICA", "MECANICO", "MECANICA", "INSTRUMENTACION",
            "PROYECTOS", "COMPRAS", "RECURSOS", "HUMANOS", "DIRECTOR", "DIRECTORA", "GENERAL", "SUBGERENTE", "PROPIEDAD",
            "EMPRESA", "DEVOLVER", "ENCUENTRA", "FAVOR", "INTRANSFERIBLE", "USO", "OBLIGATORIO", "EL",
            // Letreros de identificaciones (INE, licencia, pasaporte, gafetes)
            "NACIONALIDAD", "MEXICANA", "MEXICANO", "MEXICANOS", "ESTADOS", "UNIDOS", "REPUBLICA", "SEXO", "DOMICILIO",
            "FECHA", "NACIMIENTO", "ELECTOR", "ELECTORAL", "INSTITUTO", "NACIONAL", "VOTAR", "LICENCIA", "CONDUCIR",
            "PASAPORTE", "SANGUINEO", "ALERGIAS", "EMISION", "EXPEDICION", "ESTADO", "MUNICIPIO", "LOCALIDAD", "SECCION",
            "REGISTRO", "CARGO", "CATEGORIA", "CONTRATO", "VISITA", "PROVEEDOR", "APELLIDO", "APELLIDOS", "PATERNO",
            "MATERNO", "NOMBRES",
            // Credencial de acceso: empresas contratistas, oficios y la marca de la cámara impresa en la foto
            "SOLUCIONES", "SOLUCION", "INTEGRALES", "INTEGRAL", "SERVICIOS", "CONSTRUCTORA", "CONSTRUCCIONES",
            "INDUSTRIAL", "INDUSTRIALES", "GRUPO", "CORPORATIVO", "COMPANIA", "CONTRATISTAS", "OBRA", "CIVIL",
            "AYUDANTE", "OFICIAL", "SOLDADOR", "ELECTRICISTA", "ALBANIL", "CARPINTERO", "PLOMERO", "MANIOBRISTA",
            "ARMADOR", "TUBERO", "PAILERO", "INSPECTOR", "RESIDENTE", "CHOFER", "VELADOR", "VIGILANTE", "CAPATAZ",
            "SOBRESTANTE", "MONTADOR", "ANDAMIERO", "INSTALADOR", "TOPOGRAFO", "CADENERO", "BRIGADISTA",
            "ALERGIA", "NINGUNA", "NINGUNO", "POSITIVO", "NEGATIVO", "DIRECCION", "CONTACTO", "ESPECIALIDAD",
            "OFICIO", "SHOT", "CAMERA", "THE");
        public Set<String> noCodigo = conjunto(
            "TEL", "TELEFONO", "CEL", "CELULAR", "EMERGENCIA", "NSS", "IMSS", "AFILIACION", "VIGENCIA", "VENCE",
            "EXPIRA", "EXPEDICION", "FECHA", "NACIMIENTO", "CP", "C.P.");
        /** Palabras que no deben contar para descartar un nombre aunque estén en noNombre (p. ej. «DE LA»). */
        public Set<String> particulas = conjunto("DE", "DEL", "LA", "LAS", "LOS", "Y", "MC", "MAC", "VAN", "VON", "DA", "DI", "SAN", "SANTA");
        /** Dígitos que trae impreso el ID de la credencial (5). Con él, si una raya de la impresora se lee
         *  como un «1» de más o parte el número en pedazos, se corrige; y nunca se toma un pedazo del
         *  número (otra persona). 0 = no se sabe (cualquiera entre minDigitos y maxDigitos). */
        public int digitosId = 0;
        /** Los IDs de la base del personal, sin ceros a la izquierda. Si una lectura dudosa puede ser
         *  más de un número, se queda la que está en la base. Vacío = sin base. */
        public Set<String> ids = new HashSet<>();

        public void ponerIds(String[] lista) {
            ids.clear();
            if (lista != null) for (String x : lista) { String k = sinCeros(x); if (!k.isEmpty()) ids.add(k); }
        }
        boolean enBase(String codigo) { return !ids.isEmpty() && ids.contains(sinCeros(codigo)); }

        public static Set<String> conjunto(String... p) {
            Set<String> s = new HashSet<>();
            for (String x : p) if (x != null && !x.trim().isEmpty()) s.add(sinAcentos(x.trim()).toUpperCase(Locale.ROOT));
            return s;
        }
        public void ponerPatron(String p) {
            if (p == null || p.trim().isEmpty()) { patron = null; return; }
            try { patron = Pattern.compile(p); } catch (PatternSyntaxException e) { patron = null; }
        }
    }

    private static final Locale ES_MX = Locale.forLanguageTag("es-MX");
    private static final Pattern MARCAS = Pattern.compile("\\p{M}+");
    private static final Pattern FECHA = Pattern.compile("\\b\\d{1,2}\\s?[/.\\-]\\s?\\d{1,2}\\s?[/.\\-]\\s?\\d{2,4}\\b");
    private static final Pattern MES = Pattern.compile("\\b(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|SEPT|OCT|NOV|DIC)[A-Z]*\\b");
    /* Grupos de dígitos que no están pegados a letras (así no se toman pedazos de un CURP o un RFC) */
    private static final Pattern GRUPO = Pattern.compile("(?<![\\p{L}\\d])(\\d+(?:[ \\-]\\d+)*)(?![\\p{L}\\d])");
    private static final Pattern PALABRA = Pattern.compile("[\\p{L}][\\p{L}'\\-.]*");

    public static String sinAcentos(String t) {
        return MARCAS.matcher(Normalizer.normalize(t, Normalizer.Form.NFD)).replaceAll("");
    }

    /** «00287» → «287»: así se compara el ID (la llave de cada persona). */
    public static String sinCeros(String c) {
        String d = c == null ? "" : c.replaceAll("\\D", "");
        String s = d.replaceFirst("^0+", "");
        return s.isEmpty() && !d.isEmpty() ? "0" : s;
    }

    /* ── El renglón del ID («ID: 00287», en el recuadro azul) ──
       Algunas credenciales salen de la impresora con rayas blancas verticales que cruzan el recuadro. Las rayas
       están siempre en el mismo lugar de la credencial, pero los números no (el «1» es más angosto), así que en
       cada ID la raya cae en otro lado: entre dos números, pegada a uno o atravesándolo. El reconocimiento la
       lee como «|», «I», «l» o un «1» de más, o parte el número en dos («0 0287», «0028 7»). Todo lo que sigue
       a «ID:» en esa fila es el número: se juntan los pedazos y, si sobran caracteres, se quita la raya:
        1. Por su forma, si el reconocimiento da la caja de cada carácter: la raya es mucho más delgada que
           cualquier número, también que un «1». Sirve igual para cualquier ID.
        2. Si no, por lo que se leyó: «|» casi siempre es la raya; «I» o «l», casi; un «1» pegado a otro o al
           final, más que uno suelto.
        3. Si aun así quedan dos números posibles, decide la base del personal; sin base, no se adivina. */
    /** Letras que en el recuadro del ID se leen como un dígito (ver codigoDelId). */
    private static final String PARECE_DIGITO = "IliOoQDCSsBZzAGbTgqØøßΦφθΘ";
    /* «ID:», también con la raya pegada a la I o a la D («I|D:», «|ID:», «IID:», «ID|:») */
    private static final Pattern ETIQUETA_ID = Pattern.compile(
            "(?<![\\p{L}\\d])[I1l|!]{1,3}\\s?D(?!\\p{L})[|!]?\\s*[:;.,]?", Pattern.CASE_INSENSITIVE);

    /* Lo que cuesta quitar cada carácter del número (0: es un número, no se quita). Por lo que se leyó: «|» o
       «!» 2; «I», «l» o «i» 4; un «1» 6; pegado a otro «1» o al final, a lo más 4. Por su forma: delgado 1 (es
       la raya); del ancho de un número 8 (es un «1» de verdad). Quitar dos juntos cuesta JUNTOS más. */
    private static final int RAYA_POR_FORMA = 1, UNO_POR_FORMA = 8, JUNTOS = 3;
    /** Una opción gana sola si cuesta al menos DUDA menos que las demás; si no, decide la base (ver elegirOpcion). */
    private static final int DUDA = 3;

    static String codigoDelId(String resto, int objetivo, Reglas r) { return codigoDelId(resto, null, objetivo, r); }

    /**
     * El ID que trae «resto» (lo leído a la derecha de «ID:»), o null si no se puede saber. «cajas»: la caja de
     * cada carácter de «resto», en su misma posición (null donde no se sabe). Con «objetivo» (los dígitos del
     * ID) quita lo que sobra.
     */
    static String codigoDelId(String resto, List<Simbolo> cajas, int objetivo, Reglas r) {
        StringBuilder dig = new StringBuilder();
        List<Integer> costo = new ArrayList<>();               // lo que cuesta quitar cada dígito (0 = no se quita)
        List<Simbolo> caja = new ArrayList<>();
        List<Boolean> palo = new ArrayList<>();                 // se leyó «|» o «!»
        int i = 0, n = resto.length();
        while (i < n) {
            while (i < n && Character.isWhitespace(resto.charAt(i))) i++;
            int ini = i;
            while (i < n && !Character.isWhitespace(resto.charAt(i))) i++;
            if (ini == i) break;
            String tok = resto.substring(ini, i);
            // Sin ningún dígito, una palabra (más de dos letras, o letras que no se confunden con números) no es
            // parte del ID: ahí acaba. Con dígitos, cada letra se lee como el número al que se parece.
            if (!tok.matches(".*\\d.*")) {
                boolean palabra = tok.replaceAll("[^\\p{L}]", "").length() > 2;
                for (int k = 0; k < tok.length() && !palabra; k++)
                    palabra = Character.isLetter(tok.charAt(k)) && PARECE_DIGITO.indexOf(tok.charAt(k)) < 0;
                if (palabra) {
                    if (dig.length() > 0) break;
                    continue;
                }
            }
            for (int k = ini; k < i; k++) {
                char ch = resto.charAt(k), d; int c;
                if (ch >= '0' && ch <= '9') { d = ch; c = ch == '1' ? 6 : 0; }
                else switch (ch) {
                    case '|': case '!': d = '1'; c = 2; break;              // casi siempre es la raya
                    case 'I': case 'l': case 'i': d = '1'; c = 4; break;
                    // Un número con la raya encima se parece a una letra o a un signo: «0» → C, Ø, ¢; «4» → A;
                    // «5» → $; «6» → G, b; «7» → T; «8» → ß; «9» → g, q
                    case 'O': case 'o': case 'Q': case 'D': case 'C':
                    case 'Ø': case 'ø': case '¢': case 'Φ': case 'φ': case 'θ': case 'Θ': d = '0'; c = 0; break;
                    case 'S': case 's': case '$': d = '5'; c = 0; break;
                    case 'B': case 'ß': d = '8'; c = 0; break;
                    case 'Z': case 'z': d = '2'; c = 0; break;
                    case 'A': d = '4'; c = 0; break;
                    case 'G': case 'b': d = '6'; c = 0; break;
                    case 'T': d = '7'; c = 0; break;
                    case 'g': case 'q': d = '9'; c = 0; break;
                    default: continue;               // signo u otra letra: se salta (si faltan dígitos, no se adivina)
                }
                dig.append(d); costo.add(c); palo.add(ch == '|' || ch == '!');
                caja.add(cajas != null && k < cajas.size() ? cajas.get(k) : null);
            }
        }
        String s = dig.toString();
        if (s.isEmpty()) return null;
        if (objetivo <= 0) {                                    // sin saber cuántos: sólo se quita «|» y «!»
            StringBuilder t = new StringBuilder();
            for (int k = 0; k < s.length(); k++) if (!palo.get(k)) t.append(s.charAt(k));
            return t.length() == 0 ? null : t.toString();
        }
        boolean[] raya = rayasPorForma(costo, caja);
        boolean porForma = false;                               // se vio al menos una raya por su forma
        for (boolean x : raya) porForma |= x;
        int sobran = s.length() - objetivo;
        if (sobran < 0 || sobran > (porForma ? 3 : 2)) return null;    // falta un dígito o es otro número (NSS, teléfono)
        if (sobran == 0) {
            // Una raya que completa los dígitos ocupa el lugar de un número que no se leyó: no es el ID.
            // Un «|» puede ser eso: sólo vale si está en la base.
            if (porForma) return null;
            return palo.contains(true) && !r.enBase(s) ? null : s;
        }
        for (int k = 0; k < s.length(); k++) {
            if (costo.get(k) == 0) continue;
            if (porForma && caja.get(k) != null) { costo.set(k, raya[k] ? RAYA_POR_FORMA : UNO_POR_FORMA); continue; }
            // Sin la forma: la raya cae pegada a un número; un «1» junto a otro «1» («0|1287» → «011287») o al
            // final, pegado al último número («01287|» → «012871»), es más probablemente la raya que uno suelto.
            boolean pegado = (k > 0 && costo.get(k - 1) > 0) || (k + 1 < s.length() && costo.get(k + 1) > 0);
            if (pegado || k == s.length() - 1) costo.set(k, Math.min(costo.get(k), 4));
        }
        List<Integer> quitables = new ArrayList<>();
        for (int k = 0; k < s.length(); k++) if (costo.get(k) > 0) quitables.add(k);
        Map<String, Integer> opciones = new HashMap<>();        // número posible → lo menos que cuesta llegar a él
        quitar(s, costo, raya, quitables, 0, sobran, new ArrayList<>(), opciones);
        return elegirOpcion(opciones, r);
    }

    /**
     * Cuáles caracteres son la raya por su forma: los que se leyeron como «1», «|», «I» o «l» y miden menos de
     * la mitad de ancho que los otros números de la misma lectura (un «1» impreso mide cerca de ¾ de un «0»).
     * Si el reconocimiento no dio las cajas, o ninguno es tan delgado, todos en false: se decide por el texto.
     */
    static boolean[] rayasPorForma(List<Integer> costo, List<Simbolo> caja) {
        boolean[] raya = new boolean[costo.size()];
        List<Integer> anchos = new ArrayList<>();
        for (int k = 0; k < costo.size(); k++)
            if (costo.get(k) == 0 && caja.get(k) != null) anchos.add(caja.get(k).ancho());
        if (anchos.isEmpty()) return raya;
        Collections.sort(anchos);
        int ref = anchos.get(anchos.size() / 2);
        if (ref <= 0) return raya;
        for (int k = 0; k < costo.size(); k++)
            raya[k] = costo.get(k) > 0 && caja.get(k) != null && caja.get(k).ancho() * 100 <= ref * 45;
        return raya;
    }

    /** Todas las formas de quitar «faltan» caracteres de «quitables» (a partir del lugar «desde»), con su costo. */
    private static void quitar(String s, List<Integer> costo, boolean[] raya, List<Integer> quitables, int desde,
                               int faltan, List<Integer> elegidos, Map<String, Integer> opciones) {
        if (faltan == 0) {
            int c = 0;
            StringBuilder t = new StringBuilder(s);
            for (int j = elegidos.size() - 1; j >= 0; j--) {
                int k = elegidos.get(j);
                c += costo.get(k);
                // Dos rayas juntas en el mismo «11» es poco probable (las de la impresora están a más de dos números
                // una de otra), salvo que las dos se vean delgadas o sean las dos últimas: una raya pasada el
                // número puede leerse como dos caracteres («ll», «|I»)
                if (j > 0 && elegidos.get(j - 1) == k - 1 && !(raya[k] && raya[k - 1]) && k != s.length() - 1) c += JUNTOS;
                t.deleteCharAt(k);
            }
            Integer antes = opciones.get(t.toString());
            if (antes == null || c < antes) opciones.put(t.toString(), c);
            return;
        }
        for (int q = desde; q <= quitables.size() - faltan; q++) {
            elegidos.add(quitables.get(q));
            quitar(s, costo, raya, quitables, q + 1, faltan - 1, elegidos, opciones);
            elegidos.remove(elegidos.size() - 1);
        }
    }

    /**
     * La opción más barata, si cuesta al menos DUDA menos que todas las demás. Si no, entre las dudosas (las que
     * cuestan menos de DUDA más que la más barata) decide la base del personal, pero sólo si una sola está en la
     * base y es de las más baratas: si la más probable no está en la base y otra sí, puede ser alguien que
     * todavía no está en la base, y no se le confunde con otra persona. Si no: null (no se adivina; se vuelve
     * a leer o se escribe el ID).
     */
    private static String elegirOpcion(Map<String, Integer> opciones, Reglas r) {
        if (opciones.isEmpty()) return null;
        int min = Integer.MAX_VALUE;
        for (int v : opciones.values()) min = Math.min(min, v);
        List<String> dudosas = new ArrayList<>();
        for (Map.Entry<String, Integer> e : opciones.entrySet()) if (e.getValue() < min + DUDA) dudosas.add(e.getKey());
        if (dudosas.size() == 1) return dudosas.get(0);
        String enBase = null;
        for (String x : dudosas) {
            if (!r.enBase(x)) continue;
            if (enBase != null) return null;                    // dos personas de la base: no se sabe cuál
            enBase = x;
        }
        return enBase != null && opciones.get(enBase) == min ? enBase : null;
    }

    /** Las letras de la etiqueta de un renglón, sin espacios ni signos: «N SS: 123…» → «NSS». */
    static String etiquetaJunta(String t) {
        if (t == null) return "";
        String u = sinAcentos(t).toUpperCase(Locale.ROOT);
        int fin = 0;
        while (fin < u.length() && !Character.isDigit(u.charAt(fin))) fin++;
        return u.substring(0, fin).replaceAll("[^A-Z]", "");
    }

    /* El reconocimiento confunde letras con números dentro de un número: O→0, I/l→1, S→5, B→8, Z→2.
       «ID:0O161» se separa en «ID:» y «0O161» para corregir sólo el número. */
    static String corregirDigitos(String linea) {
        String[] tok = linea.split("(?<=[\\s:])|(?=\\s)");
        StringBuilder sb = new StringBuilder();
        for (String t : tok) {
            int d = 0, l = 0;
            for (int i = 0; i < t.length(); i++) { char c = t.charAt(i); if (Character.isDigit(c)) d++; else if (Character.isLetter(c)) l++; }
            if (d >= 3 && d > l && l <= 2) {
                StringBuilder x = new StringBuilder();
                for (int i = 0; i < t.length(); i++) {
                    char c = t.charAt(i);
                    switch (c) {
                        case 'O': case 'o': case 'Q': x.append('0'); break;
                        case 'I': case 'l': case '|': case 'i': x.append('1'); break;
                        case 'S': case 's': x.append('5'); break;
                        case 'B': x.append('8'); break;
                        case 'Z': case 'z': x.append('2'); break;
                        default: x.append(c);
                    }
                }
                sb.append(x);
            } else sb.append(t);
        }
        return sb.toString();
    }

    static List<String> palabras(String t) {
        List<String> out = new ArrayList<>();
        for (String w : sinAcentos(t).toUpperCase(Locale.ROOT).split("[^A-Z0-9#.]+")) if (!w.isEmpty()) out.add(w.replaceAll("\\.+$", ""));
        return out;
    }
    private static boolean contieneAlguna(String t, Set<String> conj) {
        for (String w : palabras(t)) if (conj.contains(w) || conj.contains(w + ".")) return true;
        return t.contains("#") && conj.contains("#");
    }
    /** Trae una palabra que suele ir junto al ID, y no es la etiqueta de otro número partida («N SS» no es «N»). */
    private static boolean esClave(String t, Reglas r) {
        return contieneAlguna(t, r.claves) && !r.noCodigo.contains(etiquetaJunta(t));
    }

    /* ── Etiquetas de los campos («NOMBRE:», «EMPRESA:», «NSS:»…) ──
       En la credencial de acceso cada dato lleva su etiqueta. El reconocimiento
       puede leer etiqueta y dato en un solo renglón («NOMBRE: JUAN PÉREZ») o
       en dos renglones a la misma altura: se juntan por su posición. */
    static final Set<String> ETIQUETAS = Reglas.conjunto(
        "NOMBRE", "NOMBRES", "EMPRESA", "COMPANIA", "CONTRATISTA", "PROVEEDOR", "CARGO", "PUESTO", "AREA",
        "DEPARTAMENTO", "DEPTO", "CATEGORIA", "ESPECIALIDAD", "OFICIO", "NSS", "IMSS", "CURP", "RFC", "TIPO",
        "SANGRE", "SANGUINEO", "RH", "ALERGIAS", "ALERGIA", "DOMICILIO", "DIRECCION", "TEL", "TELEFONO", "CEL",
        "CELULAR", "EMERGENCIA", "CONTACTO", "VIGENCIA", "VENCE", "EXPEDICION", "EMISION", "FECHA", "NACIMIENTO",
        "SEXO", "NACIONALIDAD", "ID", "FOLIO", "EMPLEADO", "FICHA", "NUMERO", "CLAVE", "CODIGO", "FIRMA");
    private static final Set<String> UNIONES = Reglas.conjunto("DE", "DEL", "LA", "EL", "Y");
    /* Etiquetas de varias palabras, juntas («TIPO DE SANGRE» → «TIPODESANGRE»), para reconocerlas aunque una
       raya de la impresora las parta («T IPO DE SANGRE:», «N SS:», «E VIPRESA:», «CAR(GO:»). */
    private static final Set<String> ETIQUETAS_JUNTAS = new HashSet<>(ETIQUETAS);
    static {
        ETIQUETAS_JUNTAS.addAll(Arrays.asList("TIPODESANGRE", "FECHADENACIMIENTO", "NUMERODEEMPLEADO",
            "TELEFONODEEMERGENCIA", "CONTACTODEEMERGENCIA", "TIPODESANGRERH"));
    }
    /* Una raya entre dos letras: espacio, «|», «!», punto o apóstrofo */
    private static final String RAYA = "[\\s|!.,'`]?";
    /* «NOMBRE:», «NOMBRE(S)», «N0MBRE» (cero en vez de O) al principio del renglón; también partida por una raya
       («N OMBRE:», «NOM|BRE») o con la M leída como «VI» / «IVI» / «RN». */
    private static final Pattern ETIQUETA_NOMBRE = Pattern.compile(
        "^\\s*[|!]?\\s*N" + RAYA + "[O0]" + RAYA + "(?:M|IVI|VI|RN)" + RAYA + "B" + RAYA + "R" + RAYA
            + "E(?:\\(S\\)|S)?(?!\\p{L})\\s*[:;.\\-]*\\s*", Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE);

    /** Si el renglón empieza con la etiqueta «NOMBRE», lo que trae después (puede ser ""); si no, null. */
    static String despuesDeNombre(String t) {
        if (t == null) return null;
        Matcher m = ETIQUETA_NOMBRE.matcher(t);
        return m.find() ? t.substring(m.end()).trim() : null;
    }

    /** «EMPRESA:», «TIPO DE SANGRE:», «NSS», «ID:»: un renglón que sólo trae el nombre de un campo. */
    static boolean esEtiqueta(String t) {
        String u = sinAcentos(t == null ? "" : t).toUpperCase(Locale.ROOT).replace("(S)", "S")
                .replaceAll("(?<=[A-Z])0|0(?=[A-Z])", "O").replaceAll("(?<=[A-Z])[1|]|[1|](?=[A-Z])", "I")
                .replaceAll("[\\s:;.\\-]+$", "").trim();
        if (u.isEmpty()) return false;
        String[] w = u.split("[\\s:;.]+");
        boolean alguna = false, todas = w.length <= 4;
        for (String x : w) {
            if (!todas) break;
            if (ETIQUETAS.contains(x)) alguna = true;
            else if (!UNIONES.contains(x)) todas = false;
        }
        if (todas && alguna) return true;
        // Partida por una raya: se juntan las letras y se compara («N SS» → «NSS», «E VIPRESA» → «EMPRESA»)
        String v = sinAcentos(t).toUpperCase(Locale.ROOT).replace("(S)", "S").replaceAll("[|!]", "")
                .replaceAll("(?<=[A-Z])0|0(?=[A-Z])", "O").replaceAll("(?<=[A-Z])1|1(?=[A-Z])", "I");
        if (v.matches(".*\\d.*")) return false;
        String junta = v.replaceAll("[^A-Z]", "");
        if (junta.length() < 2 || junta.length() > 24) return false;
        for (String x : new String[] { junta, junta.replace("IVI", "M").replace("VI", "M").replace("RN", "M") }) {
            if (ETIQUETAS_JUNTAS.contains(x)) return true;
            if (x.length() >= 6) for (String e : ETIQUETAS_JUNTAS) if (e.length() >= 6 && casiIgual(x, e)) return true;
        }
        return false;
    }

    /** Iguales salvo por una letra de más, de menos o cambiada («ALEFRGIAS» ≈ «ALERGIAS»). */
    static boolean casiIgual(String a, String b) {
        if (Math.abs(a.length() - b.length()) > 1) return false;
        int i = 0, j = 0, dif = 0;
        while (i < a.length() && j < b.length()) {
            if (a.charAt(i) == b.charAt(j)) { i++; j++; continue; }
            if (++dif > 1) return false;
            if (a.length() > b.length()) i++;
            else if (a.length() < b.length()) j++;
            else { i++; j++; }
        }
        return dif + (a.length() - i) + (b.length() - j) <= 1;
    }

    private static float centro(Linea l) { return l.arriba + l.alto / 2f; }

    /** Dos renglones a la misma altura (una etiqueta y su dato leídos por separado). */
    static boolean mismaFila(Linea a, Linea b) {
        return a != b && Math.abs(centro(a) - centro(b)) < 0.5f * Math.max(a.alto, b.alto);
    }

    /** Lo que se leyó a la izquierda en la misma fila: normalmente la etiqueta del dato. */
    private static String aLaIzquierda(List<Linea> orden, Linea x) {
        List<Linea> izq = new ArrayList<>();
        for (Linea y : orden) if (mismaFila(x, y) && y.izquierda < x.izquierda) izq.add(y);
        izq.sort((a, b) -> Integer.compare(a.izquierda, b.izquierda));            // en orden de lectura: «N SS:» y luego «12345»
        StringBuilder sb = new StringBuilder();
        for (Linea y : izq) sb.append(y.texto).append(' ');
        return sb.toString().trim();
    }

    /** El dato de otro campo: a la derecha de una etiqueta que no es «NOMBRE» (EMPRESA:, CARGO:, NSS:…). */
    private static boolean datoDeOtroCampo(List<Linea> orden, Linea x) {
        for (Linea y : orden)
            if (mismaFila(x, y) && y.izquierda < x.izquierda && esEtiqueta(y.texto) && despuesDeNombre(y.texto) == null) return true;
        return false;
    }

    /** El renglón de la fila de abajo más cercano a «xValor» (por si en esa fila hay más de uno). */
    private static Linea abajoDe(List<Linea> orden, Linea x, float xValor) {
        Linea primero = null;
        for (Linea y : orden)
            if (y != x && !mismaFila(x, y) && centro(y) > centro(x) && (primero == null || y.arriba < primero.arriba)) primero = y;
        if (primero == null) return null;
        Linea mejor = primero;
        for (Linea y : orden)
            if (y != primero && mismaFila(primero, y) && centro(y) > centro(x)
                    && Math.abs(y.izquierda - xValor) < Math.abs(mejor.izquierda - xValor)) mejor = y;
        return mejor;
    }

    private static final class Candidato {
        final String nombre; final int puntaje;
        Candidato(String nombre, int puntaje) { this.nombre = nombre; this.puntaje = puntaje; }
    }

    /**
     * El nombre que sigue a la etiqueta «NOMBRE»: en el mismo renglón, a la derecha en la misma fila o, si
     * no hay, en el renglón de abajo. Si el nombre continúa en el renglón de abajo, alineado con él y sin
     * otra etiqueta en esa fila, se junta: «NOMBRE: RAMIRO ESTEBAN» / «VILLARREAL DUARTE».
     */
    private static Candidato nombrePorEtiqueta(List<Linea> orden, Linea et, String resto, Reglas r, int altoMax) {
        Linea valor = null;
        String v;
        float xValor;
        boolean mismoRenglon = !resto.isEmpty();
        if (mismoRenglon) {
            valor = et; v = resto;
            String completo = et.texto.trim();
            xValor = et.ancho > 0
                    ? et.izquierda + et.ancho * (completo.length() - resto.length()) / (float) Math.max(1, completo.length())
                    : et.izquierda + et.alto;
        } else {
            for (Linea y : orden)                                         // a la derecha, en la misma fila
                if (mismaFila(et, y) && y.izquierda > et.izquierda && !esEtiqueta(y.texto)
                        && (valor == null || y.izquierda < valor.izquierda)) valor = y;
            if (valor == null) {                                          // abajo de la etiqueta
                Linea y = abajoDe(orden, et, et.izquierda);
                if (y != null && !esEtiqueta(y.texto)
                        && y.arriba - (et.arriba + et.alto) <= 2f * Math.max(et.alto, y.alto)) valor = y;
            }
            if (valor == null) return null;
            v = valor.texto.trim();
            xValor = valor.izquierda;
        }
        String uno = nombreValido(v, r), dos = null;
        Linea sig = abajoDe(orden, valor, xValor);
        if (sig != null && renglonDeNombre(sig.texto) && !esEtiqueta(sig.texto) && !datoDeOtroCampo(orden, sig)
                && despuesDeNombre(sig.texto) == null) {
            int alto = Math.max(valor.alto, sig.alto);
            boolean cerca = sig.arriba - (valor.arriba + valor.alto) <= 1.2f * alto;
            boolean parecido = Math.abs(valor.alto - sig.alto) <= 0.35f * alto;
            boolean alineado = Math.abs(sig.izquierda - xValor) < 2.5f * alto;
            if (cerca && parecido && alineado) {
                dos = nombreValido(v + " " + sig.texto.trim(), r);
                if (dos != null && dos.split(" ").length > 6) dos = null;
            }
        }
        String nombre = dos != null ? dos : uno;
        if (nombre == null) return null;
        int p = Math.round(40f * valor.alto / altoMax) + 40;
        if (v.equals(v.toUpperCase(Locale.ROOT))) p += 6;
        if (nombre.split(" ").length >= 3) p += 6;
        return new Candidato(nombre, p);
    }

    /** Analiza los renglones de UNA imagen. */
    public static Lectura analizar(List<Linea> lineas, Reglas r) {
        if (lineas == null || lineas.isEmpty()) return new Lectura(null, null, 0);
        List<Linea> orden = new ArrayList<>(lineas);
        orden.sort((a, b) -> a.arriba != b.arriba ? Integer.compare(a.arriba, b.arriba) : Integer.compare(a.izquierda, b.izquierda));
        int altoMax = 1;
        for (Linea l : orden) altoMax = Math.max(altoMax, l.alto);

        // ── Código ──
        // Dígitos exactos del ID: «longitud» o, si no se dio, los de la credencial («digitosId»)
        int objetivo = r.longitud > 0 ? r.longitud : (r.patron == null ? r.digitosId : 0);
        String mejorCodigo = null; int mejorPuntaje = Integer.MIN_VALUE;
        // 1) El renglón del ID: «ID:» y todo lo leído a su derecha en esa fila (el número puede venir en pedazos)
        Set<Linea> filaDelId = new HashSet<>();
        String enRecuadro = null;
        if (r.patron == null) {
            for (Linea l : orden) {
                if (filaDelId.contains(l)) continue;
                Matcher et = ETIQUETA_ID.matcher(l.texto);
                if (!et.find()) continue;
                StringBuilder resto = new StringBuilder(l.texto.substring(et.end()));
                List<Simbolo> cajas = new ArrayList<>(l.cajas().subList(et.end(), l.texto.length()));
                List<Linea> derecha = new ArrayList<>();
                for (Linea y : orden) if (mismaFila(l, y) && y.izquierda > l.izquierda) derecha.add(y);
                derecha.sort((a, b) -> Integer.compare(a.izquierda, b.izquierda));
                for (Linea y : derecha) {
                    resto.append(' ').append(y.texto);
                    cajas.add(null);
                    cajas.addAll(y.cajas());
                }
                filaDelId.add(l);
                filaDelId.addAll(derecha);
                if (enRecuadro == null && resto.toString().matches(".*\\d.*")) enRecuadro = resto.toString().trim();
                String c = codigoDelId(resto.toString(), cajas, objetivo, r);
                if (c == null) continue;
                int n = c.length();
                if (objetivo > 0 ? n != objetivo : (n < r.minDigitos || n > r.maxDigitos)) continue;
                int p = 52 + Math.round(10f * l.alto / altoMax) + (objetivo > 0 ? 10 : 0) + (r.enBase(c) ? 15 : 0);
                if (p > mejorPuntaje) { mejorPuntaje = p; mejorCodigo = c; }
            }
        }
        // 2) Los demás renglones (el ID sin su etiqueta, o tarjetas de otro formato). Los de la fila del ID ya
        //    se vieron completos: de ahí no se toma un pedazo del número.
        for (int i = 0; i < orden.size(); i++) {
            Linea l = orden.get(i);
            if (filaDelId.contains(l)) continue;
            String previo = i > 0 ? orden.get(i - 1).texto : "";
            String izq = aLaIzquierda(orden, l);                           // «ID:» o «NSS:» leídos aparte
            String tc = r.patron != null ? l.texto : corregirDigitos(l.texto);  // «0O287» → «00287»
            List<String> cands = new ArrayList<>();
            if (r.patron != null) {
                Matcher m = r.patron.matcher(l.texto);
                while (m.find()) cands.add((m.groupCount() >= 1 && m.group(1) != null ? m.group(1) : m.group()).replaceAll("\\D", ""));
            } else {
                if (FECHA.matcher(tc).find()) continue;                                  // es una fecha
                Matcher m = GRUPO.matcher(tc);
                while (m.find()) {
                    String g = m.group(1);
                    String[] partes = g.split("[ \\-]");
                    boolean todosLargos = true;
                    for (String p : partes) if (p.length() < 3) { todosLargos = false; break; }
                    if (todosLargos) cands.add(g.replaceAll("\\D", ""));                  // «0012 3456» es un solo número
                    else for (String p : partes) cands.add(p);
                }
                // Un renglón que sólo trae el número, quizá partido por una raya («0 0287», «0|0287»), o con una etiqueta
                // corta que no se leyó bien («IO: 0 0287»): todo lo que sigue es el número
                String et = etiquetaJunta(l.texto);
                if (objetivo > 0 && et.length() <= 3 && !r.noCodigo.contains(et)) {
                    int ini = 0;
                    while (ini < l.texto.length() && !Character.isDigit(l.texto.charAt(ini))) ini++;
                    if (et.isEmpty()) ini = 0;
                    String c = codigoDelId(l.texto.substring(ini), l.cajas().subList(ini, l.texto.length()), objetivo, r);
                    if (c != null && !cands.contains(c)) cands.add(c);
                }
            }
            // NSS, teléfono, fechas…, aunque una raya parta la etiqueta («N SS:» no es «N.» de número)
            boolean noCod = contieneAlguna(l.texto, r.noCodigo) || contieneAlguna(izq, r.noCodigo)
                    || r.noCodigo.contains(etiquetaJunta(l.texto)) || r.noCodigo.contains(etiquetaJunta(izq));
            for (String c : cands) {
                int n = c.length();
                if (objetivo > 0 ? n != objetivo : (n < r.minDigitos || n > r.maxDigitos)) continue;
                int p = 10;
                boolean clave = esClave(l.texto, r)
                        || ((esClave(previo, r) || esClave(izq, r)) && soloNumero(tc, c));
                if (noCod && !clave) continue;
                if (clave) p += 30;
                if (soloNumero(tc, c)) p += 12;
                if (r.enBase(c)) p += 15;
                String up = sinAcentos(l.texto).toUpperCase(Locale.ROOT);
                if (MES.matcher(up).find()) p -= 25;
                if (n == 4 && c.matches("(19|20)\\d\\d") && !clave) p -= 20;              // parece un año
                if (n == 11 && !clave && r.longitud != 11) p -= 35;                      // 11 dígitos: el NSS
                p += Math.round(10f * l.alto / altoMax);
                if (r.longitud > 0 || r.patron != null) p += 10;
                if (p > mejorPuntaje) { mejorPuntaje = p; mejorCodigo = c; }
            }
        }
        if (mejorCodigo != null && mejorPuntaje < 0) mejorCodigo = null;

        // ── Nombre ──
        String mejorNombre = null; int mejorN = Integer.MIN_VALUE;
        for (Linea l : orden) {
            String resto = despuesDeNombre(l.texto);
            if (resto != null) {                                              // etiqueta «NOMBRE»
                Candidato c = nombrePorEtiqueta(orden, l, resto, r, altoMax);
                if (c != null && c.puntaje > mejorN) { mejorN = c.puntaje; mejorNombre = c.nombre; }
                continue;
            }
            if (esEtiqueta(l.texto) || datoDeOtroCampo(orden, l)) continue;   // EMPRESA:, CARGO: y lo que traen
            String t = l.texto.trim();
            String limpio = nombreValido(t, r);
            if (limpio == null) continue;
            int p = Math.round(40f * l.alto / altoMax);
            if (t.equals(t.toUpperCase(Locale.ROOT))) p += 6;
            if (limpio.split(" ").length >= 3) p += 6;
            if (p > mejorN) { mejorN = p; mejorNombre = limpio; }
        }
        // Nombre en dos renglones sin etiqueta: «JUAN CARLOS» / «PÉREZ LÓPEZ» (mismo tamaño, uno debajo del otro)
        for (int i = 0; i + 1 < orden.size(); i++) {
            Linea a = orden.get(i), b = orden.get(i + 1);
            if (!renglonDeNombre(a.texto) || !renglonDeNombre(b.texto)) continue;
            if (esEtiqueta(a.texto) || esEtiqueta(b.texto) || datoDeOtroCampo(orden, a) || datoDeOtroCampo(orden, b)) continue;
            if (despuesDeNombre(a.texto) != null || despuesDeNombre(b.texto) != null) continue;
            int alto = Math.max(a.alto, b.alto);
            if (Math.abs(a.alto - b.alto) > 0.3f * alto) continue;                      // otra letra: no es el mismo nombre
            if (b.arriba - (a.arriba + a.alto) > 1.5f * alto) continue;                // muy separados
            boolean alineados = Math.abs(a.izquierda - b.izquierda) < 2 * alto
                    || (a.ancho > 0 && b.ancho > 0 && Math.abs((a.izquierda + a.ancho / 2) - (b.izquierda + b.ancho / 2)) < 2 * alto);
            if (!alineados) continue;
            String limpio = nombreValido(a.texto.trim() + " " + b.texto.trim(), r);
            if (limpio == null || limpio.split(" ").length > 5) continue;
            int p = Math.round(40f * (a.alto + b.alto) / 2f / altoMax) + 8;
            if ((a.texto + b.texto).equals((a.texto + b.texto).toUpperCase(Locale.ROOT))) p += 6;
            if (p > mejorN) { mejorN = p; mejorNombre = limpio; }
        }
        return new Lectura(mejorCodigo, mejorNombre, mejorCodigo == null ? 0 : mejorPuntaje, enRecuadro);
    }

    /* En una palabra que es casi toda letras, el reconocimiento a veces pone un número: «C0RONA», «MARR0QUIN».
       Un número suelto («12», «00287») no se toca: así un renglón con números sigue sin ser nombre. */
    static String corregirLetras(String t) {
        if (t == null) return "";
        StringBuilder sb = new StringBuilder();
        for (String w : t.split("(?<=\\s)|(?=\\s)")) {
            int d = 0, l = 0;
            for (int i = 0; i < w.length(); i++) { char c = w.charAt(i); if (Character.isDigit(c)) d++; else if (Character.isLetter(c)) l++; }
            if (d > 0 && l >= 3 && l >= 2 * d) {
                StringBuilder x = new StringBuilder();
                for (int i = 0; i < w.length(); i++) {
                    char c = w.charAt(i);
                    switch (c) {
                        case '0': x.append('O'); break;
                        case '1': x.append('I'); break;
                        case '5': x.append('S'); break;
                        case '8': x.append('B'); break;
                        default: x.append(c);
                    }
                }
                sb.append(x);
            } else sb.append(w);
        }
        return sb.toString();
    }

    /** Un renglón de 1 a 3 palabras, sólo letras: puede ser parte de un nombre partido en dos. */
    private static boolean renglonDeNombre(String t) {
        String x = corregirLetras(t).trim();
        if (x.isEmpty() || x.matches(".*\\d.*") || x.contains(":")) return false;
        String[] w = x.split("\\s+");
        if (w.length > 3) return false;
        for (String p : w) if (!p.matches("[\\p{L}][\\p{L}'.\\-]*")) return false;
        return true;
    }

    private static boolean soloNumero(String linea, String codigo) {
        int dig = 0, otros = 0;
        for (int i = 0; i < linea.length(); i++) {
            char c = linea.charAt(i);
            if (Character.isDigit(c)) dig++; else if (Character.isLetter(c)) otros++;
        }
        return dig >= codigo.length() && otros <= 2;
    }

    /** Regresa el nombre limpio («Juan Pérez López») o null si el renglón no parece un nombre. */
    static String nombreValido(String t, Reglas r) {
        if (t == null) return null;
        t = corregirLetras(t.replaceAll("[|_*•·,;:]+", " ").replaceAll("\\s+", " ").trim());
        if (t.isEmpty() || t.matches(".*\\d.*")) return null;
        Matcher m = PALABRA.matcher(t);
        List<String> pal = new ArrayList<>();
        int letras = 0, fuera = 0;
        while (m.find()) { pal.add(m.group()); letras += m.group().replaceAll("[^\\p{L}]", "").length(); }
        for (String w : pal) fuera += w.length();
        int sinEspacios = t.replace(" ", "").length();
        if (sinEspacios - fuera > 1) return null;                     // trae símbolos raros
        if (pal.size() < 2 || pal.size() > 6 || letras < 6) return null;
        int importantes = 0;
        for (String w : pal) {
            String u = sinAcentos(w).toUpperCase(Locale.ROOT).replaceAll("[.'\\-]", "");
            if (r.particulas.contains(u)) continue;
            if (r.noNombre.contains(u) || r.noNombre.contains(u + ".")) return null;
            if (u.length() < 2) return null;
            importantes++;
        }
        if (importantes < 2) return null;
        // Nombre propio: cada palabra con mayúscula inicial (las partículas en minúscula)
        StringBuilder sb = new StringBuilder();
        for (String w : pal) {
            String u = sinAcentos(w).toUpperCase(Locale.ROOT).replaceAll("[.'\\-]", "");
            String x = w.toLowerCase(ES_MX);
            if (!r.particulas.contains(u) || sb.length() == 0) x = Character.toUpperCase(x.charAt(0)) + x.substring(1);
            if (sb.length() > 0) sb.append(' ');
            sb.append(x);
        }
        return sb.toString();
    }

    /**
     * Junta varias imágenes seguidas: un código se da por bueno cuando aparece
     * igual en «necesarias» de las últimas «ventana» lecturas. El nombre es el
     * que más veces se leyó junto con ese código.
     */
    public static final class Estabilizador {
        private final int necesarias, ventana;
        private final Deque<String> ultimas = new ArrayDeque<>();
        private final Map<String, Map<String, Integer>> nombres = new HashMap<>();
        private final Map<String, Integer> nombresSueltos = new HashMap<>();   // nombres leídos aunque no saliera el código
        public Estabilizador(int necesarias, int ventana) { this.necesarias = Math.max(1, necesarias); this.ventana = Math.max(this.necesarias, ventana); }

        /** Agrega una lectura; regresa la confirmada o null si todavía no hay suficientes. */
        public Lectura agregar(Lectura l) {
            String c = l == null ? null : l.codigo;
            if (l != null && l.nombre != null) nombresSueltos.put(l.nombre, nombresSueltos.getOrDefault(l.nombre, 0) + 1);
            ultimas.addLast(c == null ? "" : c);
            while (ultimas.size() > ventana) ultimas.removeFirst();
            if (c == null) return null;
            if (l.nombre != null) {
                Map<String, Integer> m = nombres.computeIfAbsent(c, k -> new HashMap<>());
                m.put(l.nombre, m.getOrDefault(l.nombre, 0) + 1);
            }
            int veces = 0;
            for (String x : ultimas) if (x.equals(c)) veces++;
            if (veces < necesarias) return null;
            return new Lectura(c, nombreDe(c), l.puntaje);
        }
        public String nombreDe(String c) {
            Map<String, Integer> m = nombres.get(c);
            return m == null ? null : masVotado(m);
        }
        /**
         * El nombre con más votos. Una lectura parcial («Juan Carlos», cuando no se alcanzó a leer el
         * segundo renglón) cuenta a favor del nombre completo que empieza igual («Juan Carlos Pérez
         * López»). En empate gana el más completo.
         */
        static String masVotado(Map<String, Integer> m) {
            String mejor = null; int mv = 0;
            for (Map.Entry<String, Integer> e : m.entrySet()) {
                String n = e.getKey(); int v = e.getValue();
                for (Map.Entry<String, Integer> o : m.entrySet()) if (esInicioDe(o.getKey(), n)) v += o.getValue();
                if (mejor == null || v > mv || (v == mv && (n.length() > mejor.length()
                        || (n.length() == mejor.length() && n.compareTo(mejor) < 0)))) { mejor = n; mv = v; }
            }
            return mejor;
        }
        private static boolean esInicioDe(String parte, String todo) {
            String a = sinAcentos(parte).toUpperCase(Locale.ROOT), b = sinAcentos(todo).toUpperCase(Locale.ROOT);
            return b.length() > a.length() && b.startsWith(a + " ");
        }
        public int vecesDe(String c) { int v = 0; for (String x : ultimas) if (x.equals(c)) v++; return v; }
        /** El nombre leído con ese código o, si nunca salieron juntos, el que más se ha leído. */
        public String nombreProbable(String c) {
            String n = nombreDe(c);
            if (n != null) return n;
            return nombresSueltos.isEmpty() ? null : masVotado(nombresSueltos);
        }
    }

    /** Para pruebas y para el registro: el texto de los renglones en orden de lectura. */
    public static String unir(List<Linea> lineas) {
        List<Linea> orden = new ArrayList<>(lineas);
        orden.sort((a, b) -> a.arriba != b.arriba ? Integer.compare(a.arriba, b.arriba) : Integer.compare(a.izquierda, b.izquierda));
        StringBuilder sb = new StringBuilder();
        for (Linea l : orden) { if (sb.length() > 0) sb.append('\n'); sb.append(l.texto); }
        return sb.toString();
    }

    static List<String> lista(String... s) { return Arrays.asList(s); }
}
